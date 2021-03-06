<?php
namespace Drush\Commands\core;

use Drupal\Core\Utility\Error;
use Drupal\Core\Entity\EntityStorageException;
use Drush\Commands\DrushCommands;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drush\Drush;
use Drush\Exceptions\UserAbortException;
use Drush\Log\LogLevel;

class UpdateDBCommands extends DrushCommands
{
    protected $cache_clear;

    /**
     * Apply any database updates required (as with running update.php).
     *
     * @command updatedb
     * @option cache-clear Clear caches upon completion.
     * @option entity-updates Run automatic entity schema updates at the end of any update hooks.
     * @option post-updates Run post updates after hook_update_n and entity updates.
     * @bootstrap full
     * @aliases updb
     */
    public function updatedb($options = ['cache-clear' => true, 'entity-updates' => false, 'post-updates' => true])
    {
        $this->cache_clear = $options['cache-clear'];
        require_once DRUPAL_ROOT . '/core/includes/install.inc';
        require_once DRUPAL_ROOT . '/core/includes/update.inc';
        drupal_load_updates();

        // Disables extensions that have a lower Drupal core major version, or too high of a PHP requirement.
        // Those are rare, and this function does a full rebuild. So commenting it out for now.
        // update_fix_compatibility();

        // Check requirements before updating.
        if (!$this->updateCheckRequirements()) {
            return;
        }

        $return = drush_invoke_process('@self', 'updatedb:status', [], ['entity-updates' => $options['entity-updates'], 'post-updates' => $options['post-updates']]);
        if ($return['error_status']) {
            throw new \Exception('Failed getting update status.');
        } elseif (empty($return['object'])) {
            // Do nothing. updatedb:status already logged a message.
        } else {
            if (!$this->io()->confirm(dt('Do you wish to run the specified pending updates?'))) {
                throw new UserAbortException();
            }
            if (Drush::simulate()) {
                $success = true;
            } else {
                $success = $this->updateBatch($options);
                // Clear all caches in a new process. We just performed major surgery.
                drush_drupal_cache_clear_all();
            }

            if (!$success) {
                drush_set_context('DRUSH_EXIT_CODE', DRUSH_FRAMEWORK_ERROR);
            }

            $this->logger()->success(dt('Finished performing updates.'));
        }
    }

    /**
     * Apply pending entity schema updates.
     *
     * @command entity:updates
     * @option cache-clear Set to 0 to suppress normal cache clearing; the caller should then clear if needed.
     * @bootstrap full
     * @aliases entup,entity-updates
     *
     */
    public function entityUpdates($options = ['cache-clear' => true])
    {
        if (Drush::simulate()) {
            throw new \Exception(dt('entity-updates command does not support --simulate option.'));
        }

        if ($this->entityUpdatesMain() === false) {
            throw new \Exception('Entity updates not run.');
        }

        drush_drupal_cache_clear_all();

        $this->logger()->success(dt('Finished performing updates.'));
    }

    /**
     * List any pending database updates.
     *
     * @command updatedb:status
     * @option entity-updates Show entity schema updates.
     * @option post-updates Show post updates.
     * @bootstrap full
     * @aliases updbst,updatedb-status
     * @field-labels
     *   module: Module
     *   update_id: Update ID
     *   description: Description
     *   type: Type
     * @default-fields module,update_id,type,description
     * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
     */
    public function updatedbStatus($options = ['format'=> 'table', 'entity-updates' => true, 'post-updates' => true])
    {
        require_once DRUSH_DRUPAL_CORE . '/includes/install.inc';
        drupal_load_updates();
        list($pending, $start) = $this->getUpdatedbStatus($options);
        if (empty($pending)) {
            $this->logger()->success(dt("No database updates required."));
        } else {
            return new RowsOfFields($pending);
        }
    }

    /**
     * Perform one update and store the results which will later be displayed on
     * the finished page.
     *
     * An update function can force the current and all later updates for this
     * module to abort by returning a $ret array with an element like:
     * $ret['#abort'] = array('success' => FALSE, 'query' => 'What went wrong');
     * The schema version will not be updated in this case, and all the
     * aborted updates will continue to appear on update.php as updates that
     * have not yet been run.
     *
     * @param $module
     *   The module whose update will be run.
     * @param $number
     *   The update number to run.
     * @param $context
     *   The batch context array
     */
    public function updateDoOne($module, $number, $dependency_map, &$context)
    {
        $function = $module . '_update_' . $number;

        // If this update was aborted in a previous step, or has a dependency that
        // was aborted in a previous step, go no further.
        if (!empty($context['results']['#abort']) && array_intersect($context['results']['#abort'], array_merge($dependency_map, [$function]))) {
            return;
        }

        $context['log'] = false;

        \Drupal::moduleHandler()->loadInclude($module, 'install');

        $ret = [];
        if (function_exists($function)) {
            try {
                if ($context['log']) {
                    Database::startLog($function);
                }

                $this->logger()->notice("Executing " . $function);
                $ret['results']['query'] = $function($context['sandbox']);
                $ret['results']['success'] = true;
            } // @TODO We may want to do different error handling for different exception
            // types, but for now we'll just print the message.
            catch (\Exception $e) {
                $ret['#abort'] = ['success' => false, 'query' => $e->getMessage()];
                $this->logger()->error($e->getMessage());
            }

            if ($context['log']) {
                $ret['queries'] = Database::getLog($function);
            }
        } else {
            $ret['#abort'] = ['success' => false];
            $this->logger()->warning(dt('Update function @function not found', ['@function' => $function]));
        }

        if (isset($context['sandbox']['#finished'])) {
            $context['finished'] = $context['sandbox']['#finished'];
            unset($context['sandbox']['#finished']);
        }

        if (!isset($context['results'][$module])) {
            $context['results'][$module] = [];
        }
        if (!isset($context['results'][$module][$number])) {
            $context['results'][$module][$number] = [];
        }
        $context['results'][$module][$number] = array_merge($context['results'][$module][$number], $ret);

        if (!empty($ret['#abort'])) {
            // Record this function in the list of updates that were aborted.
            $context['results']['#abort'][] = $function;
        }

        // Record the schema update if it was completed successfully.
        if ($context['finished'] == 1 && empty($ret['#abort'])) {
            drupal_set_installed_schema_version($module, $number);
        }

        $context['message'] = 'Performing ' . $function;
    }

    /**
     * Start the database update batch process.
     */
    public function updateBatch($options)
    {
        $start = $this->getUpdateList();
        // Resolve any update dependencies to determine the actual updates that will
        // be run and the order they will be run in.
        $updates = update_resolve_dependencies($start);

        // Store the dependencies for each update function in an array which the
        // batch API can pass in to the batch operation each time it is called. (We
        // do not store the entire update dependency array here because it is
        // potentially very large.)
        $dependency_map = [];
        foreach ($updates as $function => $update) {
            $dependency_map[$function] = !empty($update['reverse_paths']) ? array_keys($update['reverse_paths']) : [];
        }

        $operations = [];

        foreach ($updates as $update) {
            if ($update['allowed']) {
                // Set the installed version of each module so updates will start at the
                // correct place. (The updates are already sorted, so we can simply base
                // this on the first one we come across in the above foreach loop.)
                if (isset($start[$update['module']])) {
                    drupal_set_installed_schema_version($update['module'], $update['number'] - 1);
                    unset($start[$update['module']]);
                }
                // Add this update function to the batch.
                $function = $update['module'] . '_update_' . $update['number'];
                $operations[] = [[$this, 'updateDoOne'], [$update['module'], $update['number'], $dependency_map[$function]]];
            }
        }

        // Perform entity definition updates, which will update storage
        // schema if needed. If module update functions need to work with specific
        // entity schema they should call the entity update service for the specific
        // update themselves.
        // @see \Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface::applyEntityUpdate()
        // @see \Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface::applyFieldUpdate()
        if ($options['entity-updates'] &&  \Drupal::entityDefinitionUpdateManager()->needsUpdates()) {
            $operations[] = [[$this, 'updateEntityDefinitions'], []];
        }

        // Lastly, apply post update hooks if specified.
        if ($options['post-updates']) {
            $post_updates = \Drupal::service('update.post_update_registry')->getPendingUpdateFunctions();
            if ($post_updates) {
                if ($operations) {
                    // Only needed if we performed updates earlier.
                    $operations[] = [[$this, 'cacheRebuild'], []];
                }
                foreach ($post_updates as $function) {
                    $operations[] = ['update_invoke_post_update', [$function]];
                }
            }
        }

        $batch['operations'] = $operations;
        $batch += [
            'title' => 'Updating',
            'init_message' => 'Starting updates',
            'error_message' => 'An unrecoverable error has occurred. You can find the error message below. It is advised to copy it to the clipboard for reference.',
            'finished' => [$this, 'updateFinished'],
            'file' => 'core/includes/update.inc',
        ];
        batch_set($batch);

        $maintenance_mode_original_state = \Drupal::service('state')->get('system.maintenance_mode');
        \Drupal::service('state')->set('system.maintenance_mode', true);
        $result = drush_backend_batch_process();
        \Drupal::service('state')->set('system.maintenance_mode', $maintenance_mode_original_state);

        $success = is_array($result) && (array_key_exists('object', $result)) && empty($result['object'][0]['#abort']);

        return $success;
    }

    /**
     * Apply entity schema updates.
     */
    public function updateEntityDefinitions(&$context)
    {
        try {
            \Drupal::entityDefinitionUpdateManager()->applyupdates();
        } catch (EntityStorageException $e) {
            watchdog_exception('update', $e);
            $variables = Error::decodeException($e);
            unset($variables['backtrace']);
            // The exception message is run through
            // \Drupal\Component\Utility\SafeMarkup::checkPlain() by
            // \Drupal\Core\Utility\Error::decodeException().
            $ret['#abort'] = ['success' => false, 'query' => t('%type: !message in %function (line %line of %file).', $variables)];
            $context['results']['core']['update_entity_definitions'] = $ret;
            $context['results']['#abort'][] = 'update_entity_definitions';
        }
    }

    // Copy of protected \Drupal\system\Controller\DbUpdateController::getModuleUpdates.
    public function getUpdateList()
    {
        $return = [];
        $updates = update_get_update_list();
        foreach ($updates as $module => $update) {
            $return[$module] = $update['start'];
        }

        return $return;
    }

    /**
     * Clears caches and rebuilds the container.
     *
     * This is called in between regular updates and post updates. Do not use
     * drush_drupal_cache_clear_all() as the cache clearing and container rebuild
     * must happen in the same process that the updates are run in.
     *
     * Drupal core's update.php uses drupal_flush_all_caches() directly without
     * explicitly rebuilding the container as the container is rebuilt on the next
     * HTTP request of the batch.
     *
     * @see drush_drupal_cache_clear_all()
     * @see \Drupal\system\Controller\DbUpdateController::triggerBatch()
     */
    public function cacheRebuild()
    {
        drupal_flush_all_caches();
        \Drupal::service('kernel')->rebuildContainer();
    }

    /**
     * Process and display any returned update output.
     *
     * @see \Drupal\system\Controller\DbUpdateController::batchFinished()
     * @see \Drupal\system\Controller\DbUpdateController::results()
     *
     * @param boolean $success Whether the batch ended without a fatal error.
     * @param array $results
     * @param array $operations
     */
    public function updateFinished($success, $results, $operations)
    {
        if (!$this->cache_clear) {
            $this->logger()->info(dt("Skipping cache-clear operation due to --no-cache-clear option."));
        } else {
            drupal_flush_all_caches();
        }

        // Log update results.
        unset($results['#abort']);
        foreach ($results as $module => $updates) {
            foreach ($updates as $number => $update) {
                if (empty($update['#abort']) && $update['results']['success'] && !empty($update['results']['query'])) {
                    $this->logger()->notice(strip_tags($update['results']['query']));
                }
            }
        }
    }

    /**
     * Return a 2 item array with
     *  - an array where each item is a 4 item associative array describing a pending update.
     *  - an array listing the first update to run, keyed by module.
     */
    public function getUpdatedbStatus(array $options)
    {
        require_once DRUPAL_ROOT . '/core/includes/update.inc';
        $pending = \update_get_update_list();

        $return = [];
        // Ensure system module's updates run first.
        $start['system'] = [];

        foreach ($pending as $module => $updates) {
            if (isset($updates['start'])) {
                foreach ($updates['pending'] as $update_id => $description) {
                    // Strip cruft from front.
                    $description = str_replace($update_id . ' -   ', '', $description);
                    $return[$module . "_update_$update_id"] = [
                        'module' => $module,
                        'update_id' => $update_id,
                        'description' => $description,
                        'type'=> 'hook_update_n'
                    ];
                }
                if (isset($updates['start'])) {
                    $start[$module] = $updates['start'];
                }
            }
        }

        // Append row(s) for pending entity definition updates.
        if ($options['entity-updates']) {
            foreach (\Drupal::entityDefinitionUpdateManager()
                         ->getChangeSummary() as $entity_type_id => $changes) {
                foreach ($changes as $change) {
                    $return[] = [
                        'module' => dt('@type entity type', ['@type' => $entity_type_id]),
                        'update_id' => '',
                        'description' => strip_tags($change),
                        'type' => 'entity-update'
                    ];
                }
            }
        }

        // Pending hook_post_update_X() implementations.
        $post_updates = \Drupal::service('update.post_update_registry')->getPendingUpdateInformation();
        if ($options['post-updates']) {
            foreach ($post_updates as $module => $post_update) {
                foreach ($post_update as $key => $list) {
                    if ($key == 'pending') {
                        foreach ($list as $id => $item) {
                            $return[$module . '-post-' . $id] = [
                                'module' => $module,
                                'update_id' => $id,
                                'description' => $item,
                                'type' => 'post-update'
                            ];
                        }
                    }
                }
            }
        }

        return [$return, $start];
    }

    /**
     * Apply pending entity schema updates.
     */
    public function entityUpdatesMain()
    {
        $change_summary = \Drupal::entityDefinitionUpdateManager()->getChangeSummary();
        if (!empty($change_summary)) {
            $this->output()->writeln(dt('The following updates are pending:'));
            $this->io()->newLine();

            foreach ($change_summary as $entity_type_id => $changes) {
                $this->output()->writeln($entity_type_id . ' entity type : ');
                foreach ($changes as $change) {
                    $this->output()->writeln(strip_tags($change), 2);
                }
            }

            if (!$this->io()->confirm(dt('Do you wish to run all pending updates?'))) {
                throw new UserAbortException();
            }

            $operations[] = [[$this, 'updateEntityDefinitions'], []];


            $batch['operations'] = $operations;
            $batch += [
                'title' => 'Updating',
                'init_message' => 'Starting updates',
                'error_message' => 'An unrecoverable error has occurred. You can find the error message below. It is advised to copy it to the clipboard for reference.',
                'finished' => [$this, 'updateFinished'],
            ];
            batch_set($batch);
            $maintenance_mode_original_state = \Drupal::service('state')->get('system.maintenance_mode');
            \Drupal::service('state')->set('system.maintenance_mode', true);
            drush_backend_batch_process();
            \Drupal::service('state')->set('system.maintenance_mode', $maintenance_mode_original_state);
        } else {
            $this->logger()->success(dt("No entity schema updates required"));
        }
    }

    /**
     * Log messages for any requirements warnings/errors.
     */
    public function updateCheckRequirements()
    {
        $continue = true;

        \Drupal::moduleHandler()->resetImplementations();
        $requirements = update_check_requirements();
        $severity = drupal_requirements_severity($requirements);

        // If there are issues, report them.
        if ($severity != REQUIREMENT_OK) {
            if ($severity === REQUIREMENT_ERROR) {
                $continue = false;
            }
            foreach ($requirements as $requirement) {
                if (isset($requirement['severity']) && $requirement['severity'] != REQUIREMENT_OK) {
                    $message = isset($requirement['description']) ? $requirement['description'] : '';
                    if (isset($requirement['value']) && $requirement['value']) {
                        $message .= ' (Currently using '. $requirement['title'] .' '. $requirement['value'] .')';
                    }
                    $log_level = $requirement['severity'] === REQUIREMENT_ERROR ? LogLevel::ERROR : LogLevel::WARNING;
                    $this->logger()->log($log_level, $message);
                }
            }
        }

        return $continue;
    }
}
