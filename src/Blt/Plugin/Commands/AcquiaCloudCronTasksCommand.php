<?php

namespace AcquiaCloudCronTasks\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;
use Acquia\Blt\Robo\Common\YamlMunge;
use Acquia\Blt\Robo\Exceptions\BltException;
use Acquia\DrupalEnvironmentDetector\AcquiaDrupalEnvironmentDetector;
use AcquiaCloudApi\Connector\Client;
use AcquiaCloudApi\Connector\Connector;
use AcquiaCloudApi\Endpoints\Applications;
use AcquiaCloudApi\Endpoints\Crons;
use AcquiaCloudApi\Endpoints\Environments;
use AcquiaCloudApi\Endpoints\Servers;

/**
 * Define commands in the acquia-cloud-crons:* namespace.
 */
class AcquiaCloudCronTasksCommand extends BltTasks {

  /**
   * The client to connect Acquia Cloud API.
   *
   * @var Client
   */
  protected Client $client;

  /**
   * The identified list of config files to load.
   *
   * @var string[]
   */
  protected array $files;

  /**
   * Some default config to be applied to all cron tasks.
   *
   * @var string[]
   */
  protected array $default_config;

  /**
   * The UUID of the environment.
   *
   * @var string
   */
  protected string $environment_uuid;

  /**
   * The UUID of the application.
   *
   * @var string
   */
  protected string $application_uuid;

  /**
   * The constructor.
   */
  public function __construct() {
    $api_cred_file = getenv('HOME') . '/acquia_cloud_api_creds.php';
    if (!file_exists($api_cred_file)) {
      throw new \Exception('Acquia cloud cred file acquia_cloud_api_creds.php missing at home directory.');
    }

    $_clientId = '';
    $_clientSecret = '';
    require $api_cred_file;

    $config = [
      'key' => $_clientId,
      'secret' => $_clientSecret,
    ];

    $connector = new Connector($config);
    $this->client = Client::factory($connector);
  }

  /**
   * Helper function to find the application's uuid from its name.
   *
   * @param string $application_name
   *   The application name.
   *
   * @return string|false
   */
  public function getApplicationUuid(string $application_name) {
    $applications_connector = new Applications($this->client);;
    $applications = $applications_connector->getAll();

    foreach ($applications as $application) {
      if (isset($application->hosting->id)) {
        $parts = explode(':', $application->hosting->id);

        if (isset($parts[1]) && strtolower($application_name) === strtolower($parts[1])) {
          return $application->uuid;
        }
      }
    }

    return FALSE;
  }

  /**
   * Helper function to find the environment's uuid from its name and parent's
   * application.
   *
   * @param string $application_uuid
   *   The parent application's uuid.
   * @param string $environment_name
   *   The environment's name.
   *
   * @return string|false
   */
  public function getEnvironmentUuid(string $application_uuid, string $environment_name) {
    $environments_connector = new Environments($this->client);
    $environments = $environments_connector->getAll($application_uuid);

    foreach ($environments as $environment) {
      if (isset($environment->name) && strtolower($environment->name) === strtolower($environment_name)) {
        return $environment->uuid;
      }
    }

    return FALSE;
  }

  /**
   * Helper function to find the server's id from its name and parent's env.
   *
   * @param string $environment_uuid
   *   The environment's uuid.
   * @param string $server_name
   *   The server's name.
   *
   * @return string
   */
  public function getServerId(string $environment_uuid, string $server_name) : string {
    $servers_connector = new Servers($this->client);
    $servers = $servers_connector->getAll($environment_uuid);

    foreach ($servers as $server) {
      if ($server->name === $server_name) {
        return $server->id;
      }
    }

    return '';

  }

  /**
   * Identify the YML files to load the cron config from.
   *
   * @param string $application_name
   *   The application's name.
   *
   * @return array
   */
  public function getYmlFiles(string $application_name) : array {
    $files = [
      $this->getConfigValue('repo.root') . '/blt/blt.yml',
      $this->getConfigValue('repo.root') . '/blt/crons.yml',
      $this->getConfigValue('repo.root') . '/blt/' . $application_name . '.crons.yml'
    ];

    return array_filter($files, function ($file) { return file_exists($file); });
  }

  /**
   * Helper function to merge 2 tasks.
   *
   * @param array $t1
   *   The first task.
   * @param array $t2
   *   The second task.
   *
   * @return array
   */
  public function mergeTasks(array $t1 = [], array $t2 = []) : array {
    // Exist early if any of the array is empty.
    if (empty($t1) || empty($t2)) {
      if (!empty($t1)) {
        return $t1;
      }
      elseif (!empty($t2)) {
        return $t2;
      }

      return [];
    }

    foreach ($t2 as $key => $t) {
      $t1[$key] = isset($t1[$key]) ? array_merge($t1[$key], $t) : $t;
    }

    return $t1;
  }

  /**
   * Helper function to apply overrides to the task based on the environment.
   *
   * @param string $environment
   *   The name of the environment.
   * @param array $tasks
   *   The list of tasks.
   *
   * @return array
   */
  public function applyEnvironmentOverrides(string $environment, array $tasks = []) : array {
    foreach ($tasks as &$task) {
      if (!isset($task['overrides'])) {
        continue;
      }

      foreach ($task['overrides'] as $override) {
        // Determine if the override applies to this environment.
        if (isset($override['environments'])) {
          $override_applies = FALSE;
          foreach ($override['environments'] as $override_environment) {
            if ((!$this->default_config['overrides_environments_contains'] && $environment == $override_environment)
              || ($this->default_config['overrides_environments_contains'] && str_contains($environment, $override_environment))) {
              $override_applies = TRUE;
              break;
            }
          }

          // If the override does not apply to this environment, skip it
          if (!$override_applies) {
            continue;
          }

          // Now that we know it applies, we can unset the environments key,
          // so we can easily merge the other override keys with the task ones.
          unset($override['environments']);
        }

        $task = array_merge($task, $override);
      }

      unset($task['overrides']);
    }

    return $tasks;
  }

  /**
   * Load the configured tasks from the YML files.
   *
   * @param string $environment
   *   The environment's name.
   *
   * @return array
   */
  public function getConfiguredCrons(string $environment) : array {
    $tasks = [];

    // Loop through the files, fetch, merge and override tasks to build config.
    foreach ($this->files as $file) {
      $config = YamlMunge::parseFile($file);
      if (isset($config['crons']['tasks'])) {
        $tasks = $this->mergeTasks($tasks, $config['crons']['tasks']);
        $tasks = $this->applyEnvironmentOverrides($environment, $tasks);
      }
    }

    // Remove tasks which status is "-1" so they don't get added at all.
    return array_filter($tasks, function ($task) { return !(isset($task['status']) && $task['status'] == '-1'); });
  }

  /**
   * Helper function to normalize the tasks loaded from the YML files.
   *
   * @param array $crons
   *  The crons loaded from the YML files.
   *
   * @return array
   */
  public function normalizeConfiguredCrons(array $crons = []) : array {
    foreach ($crons as &$cron) {
      // Assuming default status is "Enabled" for task which status is not set.
      $cron['status'] = $cron['status'] ?? 1;

      // Change the status from string to boolean. It was not possible in the
      // YML file as we want to allow "-1".
      $cron['status'] = boolval($cron['status']);

      // Set the server id based on default config. This will be empty on
      // non-prod environments.
      $cron['server_id'] = $this->default_config['server_id'] ?? '';
    }

    return $crons;
  }

  /**
   * Helper function to normalize the tasks loaded from Acquia.
   *
   * @param $crons
   *   The tasks to normalize.
   *
   * @return array
   */
  public function normalizeAcquiaCrons($crons = []) : array {
    $tasks = [];
    foreach ($crons as $cron) {
      // Acquia Cloud adds a leading '#' on command when the task is disabled.
      // To easily compare with the tasks configured in YML, we remove it here.
      $status = $cron->flags->enabled ?? 0;
      $command = $cron->command ?? '';
      if (!$status && str_starts_with($command, '# ')) {
        $command = substr($command, 2);
      }

      $tasks[$cron->label ?? $cron->id] = [
        'id' => $cron->id,
        'label' => $cron->label ?? '',
        'command' => $command,
        'frequency' => $cron->minute . ' ' . $cron->hour . ' ' . $cron->dayMonth . ' ' . $cron->month . ' ' . $cron->dayWeek,
        'status' => $status,
        'server_id' => $cron->server->id ?? '',
      ];
    }

    return $tasks;
  }

  /**
   * Helper function to compare configured tasks and Acquia Cloud ones.
   *
   * @param array $acquia_crons
   *   The tasks configured on Acquia Cloud.
   * @param array $configured_crons
   *   The tasks configured in the YML files.
   *
   * @return array[]
   */
  public function diffAcquiaAndConfiguredCrons(array $acquia_crons = [], array $configured_crons = []) : array {
    $tasks = [
      'to_edit' => [],
      'to_create' => [],
      'to_delete' => [],
    ];

    $mapped = [];
    foreach ($configured_crons as $configured_cron) {
      // This is a new cron task, it will be created.
      if (!isset($acquia_crons[$configured_cron['label']])) {
        $tasks['to_create'][$configured_cron['label']] = $configured_cron;
        continue;
      }

      $mapped[$configured_cron['label']] = $configured_cron['label'];
      $configured_cron['id'] = $acquia_crons[$configured_cron['label']]['id'];

      if (array_diff_assoc($configured_cron, $acquia_crons[$configured_cron['label']])) {
        $tasks['to_edit'][$configured_cron['label']] = $configured_cron;
      }
    }

    $tasks['to_delete'] = array_diff_key($acquia_crons, $mapped);

    return $tasks;
  }

  /**
   * Helper function to get default config used to alter the default behavior.
   *
   * @param string $environment
   *  The environment's name.
   *
   * @return void
   */
  public function getDefaultConfig(string $environment) : void {
    $is_prod = AcquiaDrupalEnvironmentDetector::isAhProdEnv($environment);

    // By default, we use strict comparison btw the environment name and the
    // override's environment name.
    $this->default_config['overrides_environments_contains'] = FALSE;

    // Loop through the files to get some default config.
    foreach ($this->files as $file) {
      $config = YamlMunge::parseFile($file);
      if (isset($config['crons'])) {
        if ($is_prod && isset($config['crons']['server'])) {
          $this->default_config['server'] = $config['crons']['server'];
        }

        if (isset($config['crons']['overrides-environments-contains'])) {
          $this->default_config['overrides_environments_contains'] = $config['crons']['overrides-environments-contains'];
        }
      }
    }

    if (isset($this->default_config['server'])) {
      $this->default_config['server_id'] = $this->getServerId($this->environment_uuid, $this->default_config['server']);

      if (empty($this->default_config['server_id'])) {
        $this->logger->warning('Impossible to find a server "' . $this->default_config['server'] . '" within environment "' . $environment . '" (uuid: ' . $this->environment_uuid . '). lease verify the server exists and the API token has the appropriate permissions.');
      }
    }
  }

  /**
   * Update Acquia Cloud Scheduled Task via the API based on the tasks
   * configured in YML files.
   *
   * @param string $application
   *   The name of the application.
   * @param string $environment
   *   The name of the environment.
   * @param array $options
   *   Options that can be passed via the CLI.
   *
   * @command acquia-cloud-crons:update-cron-tasks
   *
   * @aliases  ac:crons
   *
   * @throws \Acquia\Blt\Robo\Exceptions\BltException
   */
  public function updateCronTasks(string $application = '', string $environment = '', array $options = [
    'dry-run' => FALSE,
    'no-delete' => FALSE,
  ]) {
    // If no application is given, try to detect from AH environment variable.
    if (empty($application)) {
      $application = getenv('AH_SITE_GROUP');

      // If still empty, we can't proceed further.
      if (empty($application)) {
        throw new BltException('Application not passed and not available in ENV as well.');
      }
    }

    // If no environment is given, try to detect from AH environment variable.
    if (empty($environment)) {
      $environment = getenv('AH_SITE_ENVIRONMENT');

      // If still empty, we can't proceed further.
      if (empty($environment)) {
        throw new BltException('Environment not passed and not available in ENV as well.');
      }
    }

    $this->application_uuid = $this->getApplicationUuid($application);
    if (!$this->application_uuid) {
      throw new BltException("Impossible to find an application \"$application\". Please verify the application exists and the API token has the appropriate permissions.");
    }

    $this->environment_uuid = $this->getEnvironmentUuid($this->application_uuid, $environment);
    if (!$this->environment_uuid) {
      throw new BltException("Impossible to find an environment \"$environment\" within application \"$application\" (uuid: $this->application_uuid). Please verify the environment exists and the API token has the appropriate permissions.");
    }

    // If the command is executed in dry-run mode, print a message to inform
    // no operation will be done.
    if ($options['dry-run']) {
      $this->logger->warning('This will be a dry run, scheduled tasks will not be altered.');
    }

    // Identify the YML files which will be used to fetch configured crons.
    $this->files = $this->getYmlFiles($application);

    $this->getDefaultConfig($environment);

    // Fetch the crons from the API and normalize the format, so it can
    // easily be compared with the crons from the YML files.
    $crons_connector = new Crons($this->client);
    $acquia_crons = $crons_connector->getAll($this->environment_uuid);
    $acquia_crons = $this->normalizeAcquiaCrons($acquia_crons);

    // Fetch the crons from the YML files.
    $configured_crons = $this->getConfiguredCrons($environment);
    $configured_crons = $this->normalizeConfiguredCrons($configured_crons);

    // Generate the diff between crons from the API and the crons from the
    // YML files to determine which ones to create, edit or delete.
    list('to_edit' => $crons_to_edit, 'to_create' => $crons_to_create, 'to_delete' => $crons_to_delete) = $this->diffAcquiaAndConfiguredCrons($acquia_crons, $configured_crons);

    if ($options['no-delete']) {
      $crons_to_delete = [];
    }

    // If the cron tasks are up-to-date, print a success message and exit
    // early.
    if (empty($crons_to_edit) && empty($crons_to_create) && empty($crons_to_delete)) {
      $this->logger->success('All scheduled tasks are up-to-date.');
      return TRUE;
    }

    // Print the cron tasks which will be edited.
    foreach ($crons_to_edit as $key => $cron) {
      $this->logger->notice('Existing scheduled task "' . $cron['label'] . '" (' . $cron['id'] . ') will be edited with the following config:');
      $this->logger->notice('  Command: "' . $cron['command'] . '"' . (($cron['command'] !== $acquia_crons[$key]['command']) ? ' (Previously "' . $acquia_crons[$key]['command'] .'")' : ''));
      $this->logger->notice('  Frequency: "' . $cron['frequency'] . '"' . (($cron['frequency'] !== $acquia_crons[$key]['frequency']) ? ' (Previously "' . $acquia_crons[$key]['frequency'] .'")' : ''));
      $this->logger->notice('  Status: ' . ($cron['status'] ? 'Enabled' : 'Disabled') . (($cron['status'] != $acquia_crons[$key]['status']) ? ' (Previously "' . ($acquia_crons[$key]['status'] ? 'Enabled' : 'Disabled') .'")' : ''));
      if ($cron['server_id'] || $cron['server_id'] !== $acquia_crons[$key]['server_id']) {
        $this->logger->notice('  Server ID: ' . ($cron['server_id'] ?: '- Any server -') . (($cron['server_id'] !== $acquia_crons[$key]['server_id']) ? ' (Previously "' . ($acquia_crons[$key]['server_id'] ?: '- Any server -') . '")' : ''));
      }
    }

    // Print the cron tasks which will be created.
    foreach ($crons_to_create as $cron) {
      $this->logger->notice('New scheduled task "' . $cron['label'] . '" will be created with the following config:');
      $this->logger->notice('  Command: "' . $cron['command'] . '"');
      $this->logger->notice('  Frequency: "' . $cron['frequency'] . '"');
      $this->logger->notice('  Status: ' . ($cron['status'] ? 'Enabled' : 'Disabled'));
      if ($cron['server_id']) {
        $this->logger->notice('  Server ID: ' . $cron['server_id']);
      }
    }

    // Print the cron tasks which will be deleted.
    foreach ($crons_to_delete as $cron) {
      $this->logger->notice('Scheduled task "' . $cron['label'] . '" (' . $cron['id'] . ') will be deleted.');
    }

    // If the command is executed in dry-run mode, exit early before actually
    // updating the tasks on Acquia Cloud.
    if ($options['dry-run']) {
      return FALSE;
    }

    $failure = FALSE;

    // Invoke the API to create cron tasks.
    foreach ($crons_to_create as &$cron) {
      try {
        // To disable cron tasks, Acquia Cloud simply adds a leading '#'. To
        // avoid creating it in enabled state and disable it after, adding
        // the leading '#' will automatically create it as disabled.
        $command = $cron['status'] ? $cron['command'] : '# ' . $cron['command'];

        $response = $crons_connector->create(
          $this->environment_uuid,
          $command,
          $cron['frequency'],
          $cron['label'],
          $cron['server_id'] ?: null
        );

        $exploded_href = explode('/',$response->links->self->href);
        $cron['id'] = array_pop($exploded_href);

        $this->logger->success('Scheduled task "' . $cron['label'] . '" (' . $cron['id'] . ') has been created with success.');
      }
      catch (\Exception $e) {
        $failure = TRUE;
        $this->logger->error('Create operation for task "' . $cron['label'] . '" failed with the message: "' . trim($e->getMessage()) . '".');
      }
    }

    // Invoke the API to edit cron tasks.
    foreach ($crons_to_edit as $key => $cron) {
      try {

        if ($cron['command'] != $acquia_crons[$key]['command']
          || $cron['frequency'] != $acquia_crons[$key]['frequency']
          || $cron['label'] != $acquia_crons[$key]['label']
          || $cron['server_id'] != $acquia_crons[$key]['server_id']) {

          // To disable cron tasks, Acquia Cloud simply adds a leading '#'. To
          // avoid creating it in enabled state and disable it after, adding
          // the leading '#' will automatically create it as disabled.
          $command = $cron['status'] ? $cron['command'] : '# ' . $cron['command'];

          $crons_connector->update(
            $this->environment_uuid,
            $cron['id'],
            $command,
            $cron['frequency'],
            $cron['label'],
            $cron['server_id'] ?: null
          );

          $this->logger->success('Scheduled task "' . $cron['label'] . '" (' . $cron['id'] . ') has been updated with success.');
        }
        else {
          if ($cron['status']) {
            $crons_connector->enable(
              $this->environment_uuid,
              $cron['id']
            );

            $this->logger->success('Scheduled task "' . $cron['label'] . '" (' . $cron['id'] . ') has been enabled with success.');
          }
          else {
            $crons_connector->disable(
              $this->environment_uuid,
              $cron['id']
            );

            $this->logger->success('Scheduled task "' . $cron['label'] . '" (' . $cron['id'] . ') has been disabled with success.');
          }
        }
      }
      catch (\Exception $e) {
        $failure = TRUE;
        $this->logger->error('Edit operation for task "' . $cron['label'] . '" (' . $cron['id'] . ') failed with the message: "' . trim($e->getMessage()) . '".');
      }
    }

    // Invoke the API to delete cron tasks.
    foreach ($crons_to_delete as $cron) {
      try {
        $crons_connector->delete(
          $this->environment_uuid,
          $cron['id']
        );

        $this->logger->success('Scheduled task "' . $cron['label'] . '" (' . $cron['id'] . ') has been deleted with success.');
      }
      catch (\Exception $e) {
        $failure = TRUE;
        $this->logger->error('Delete operation for task "' . $cron['label'] . '" (' . $cron['id'] . ') failed with the message: "' . trim($e->getMessage()) . '".');
      }
    }

    if ($failure) {
      $this->logger->error('At least one operation failed. Check previous messages.');
      return FALSE;
    }

    return TRUE;
  }
}
