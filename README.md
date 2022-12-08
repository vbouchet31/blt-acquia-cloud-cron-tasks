Acquia BLT Plugin to manage Acquia Cloud cron tasks
====

This is an [Acquia BLT](https://github.com/acquia/blt) plugin that provides command to manage cron tasks on Acquia Cloud.

This plugin is **community-created** and **community-supported**. Acquia does not provide any direct support for this software or provide any warranty as to its stability.

## Quickstart
To use this plugin on your existing BLT project, require the plugin with Composer:

`composer require vbouchet31/blt-acquia-cloud-cron-tasks`

This plugin leverages [Acquia Cloud API](https://cloudapi-docs.acquia.com/). The plugin
will look for a file `acquia_cloud_api_creds.php` in the HOME directory. Here is how
the file must be formatted for the plugin to load it:
```php
<?php

$_clientId = 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxx';
$_clientSecret = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
```

In your `blt.yml` file, create a new section:
```yaml
crons:
  tasks:
    drupal_cron:
      label: "Drupal cron"
      command: "/usr/local/bin/drush -r /var/www/html/${AH_SITE_NAME}/docroot cron"
      frequency: "0 * * * *"
```

Run `blt acquia-cloud-cron-tasks:update {{application}} {{environment}}` to synchronize the
cron tasks configured in `blt.yml` and the scheduled tasks configured on Acquia Cloud.
Running this command will:
- Delete the scheduled tasks which are configured on Acquia Cloud but not configured in
`blt.yml`.
- Update the scheduled tasks which are different between Acquia Cloud and `blt.yml`. The
mapping is done based on the task's label.
- Create the tasks which are in `blt.yml` but not on Acquia Cloud.
## Advanced usage

### BLT command's options

`--no-delete`: No scheduled task will be deleted on Acquia Cloud. Only update and create
operations will be done. It can be used if you want to manage some tasks only via the UI.

`--dry-run`: No action will be taken on Acquia Cloud. It will only prompt a detailed list
of the tasks that would be created, updated or deleted without the option.

```
$ blt acct:up my_app prod --dry-run
 [warning] This will be a dry run, scheduled tasks will not be altered.
 [notice] Existing scheduled task "Drupal cron" (xxxxxx-xxxx-xxxx-xxxxxxxx) will be edited with the following config:
 [notice]   Command: "/usr/local/bin/drush -r /var/www/html/${AH_SITE_NAME}/docroot cron"
 [notice]   Frequency: "0 * * * *" (Previously "* * * * *")
 [notice]   Status: Enabled
```

### Overrides

There may be situation where the configuration of a cron task must be slightly different per environment.
A basic example would be a task running every minute on production but running only every 10 minutes on
other environments to preserve server's resources. Here is an example:

```yaml
crons:
  tasks:
    scheduler:
      label: "Scheduler"
      command: "/usr/local/bin/drush -r /var/www/html/${AH_SITE_NAME}/docroot scheduler:cron"
      frequency: "*/10 * * * *"
      overrides:
        -
          environments:
            - prod
          frequency: "* * * * *"
```

In case of multiple overrides applicable to an environment, they will be applied in the same order as listed
in the config:

```yaml
crons:
  tasks:
    scheduler:
      label: "Scheduler"
      command: "/usr/local/bin/drush -r /var/www/html/${AH_SITE_NAME}/docroot scheduler:cron"
      frequency: "*/10 * * * *"
      overrides:
        -
          environments:
            - prod
          frequency: "* * * * *"
        -
          environments:
            - prod
            - uat
          command: "/usr/local/bin/drush -r /var/www/html/${AH_SITE_NAME}/docroot scheduler:cron &>> scheduler.log" 
```

On this example, the prod environment will have a `* * * * *` frequency and will log the command result
in `scheduler.log`.

### YAML files split

To avoid making the `blt.yml` file too long due to many cron tasks, it is possible to split the configuration
in a `crons.yml` and/or a `{{application}}.crons.yml` file.
It is even possible to use all these files at the same time. The files are loaded in the following order:
`blt.yml`, `crons.yml` and `{{application}}.crons.yml`. The cron tasks will be merged based on the config
key (in the previous examples, `drupal_cron` and `scheduler` are the config keys).

One advanced use case would be managing multiple applications within the same codebase.

`crons.yml`
```yaml
crons:
  tasks:
    drupal_cron:
      label: "Drupal cron"
      command: "/usr/local/bin/drush -r /var/www/html/${AH_SITE_NAME}/docroot cron"
      frequency: "0 * * * *"
```

`application1.crons.yml`
```yaml
crons:
  tasks:
    drupal_cron:
      frequency: "5 * * * *"
    queue_run:
      label: "Queue run"
      command: "/usr/local/bin/drush -r /var/www/html/${AH_SITE_NAME}/docroot queue:run queue_name"
      frequency: "*/10 * * * *"
```

`application2.crons.yml`
````yaml
crons:
  tasks:
    drupal_cron:
      frequency: "10 * * * *"
````

With this configuration, the `drush cron` command will be executed every hour at xx:05 on application1,
every hour at xx:10 on application2 and every hour at xx:00 on any other application. Application1 will
have an extra `Queue run` scheduled task.

### Tasks status

By default, all the tasks are enabled. It is possible to disable using `status: 0`. The same attribute
can be used to avoid a task to be installed at all on one environment for example using `status: -1`.

```yaml
crons:
  tasks:
    drupal_cron:
      label: "Drupal cron"
      command: "/usr/local/bin/drush -r /var/www/html/${AH_SITE_NAME}/docroot cron"
      frequency: "0 * * * *"
      overrides:
        -
          environments:
            - dev
          status: 0
        -
          environments:
            - test
          status: -1
```

With this example, the `Drupal cron` task will be disabled on dev environment, won't be configured at
all on the test environment and will be enabled on all the others.

### Additional options

#### Overrides matching

By default, the environment's name matching for the overrides is strict. The override only applies if the
environment is explicitly listed. The `overrides-environments-contains: true` option can make this
matching more permissive and apply the overrides if one of the listed value is contained in the
environment name. One example is an application which has `dev`, `dev2`, `dev3` environments and want to
apply an override to all these environments.

```yaml
crons:
  overrides-environments-contains: true
  
  tasks:
    drupal_cron:
      label: "Drupal cron"
      command: "/usr/local/bin/drush -r /var/www/html/${AH_SITE_NAME}/docroot cron"
      frequency: "0 * * * *"
      overrides:
        -
          environments:
            - dev
          status: 0
```

In this example, the `Drupal cron` task will be disabled on `dev`, `dev2` and `dev3` environments.

#### Production server name

This option is only available with client's id and secret which belong to an advanced user. It allows
to specify on which web server to execute the cron task on the production environment. By default,
the cron task will be executed on any of the web server of the application. Some complex applications 
which require many or heavy cron tasks can have one of the web server excluded from the load balancers
rotation and used to execute cron tasks only.

```yaml
crons:
  server: web-1111

  tasks:
    drupal_cron:
      label: "Drupal cron"
      command: "/usr/local/bin/drush -r /var/www/html/${AH_SITE_NAME}/docroot cron"
      frequency: "0 * * * *"
```

In this example, the `Drupal cron` will be configured to run on the server `web-1111` on prod environment.
