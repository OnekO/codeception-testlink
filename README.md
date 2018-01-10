[WORK IN PROGRESS - NOT USABLE YET]

As you can see, this is heavily based on https://github.com/bookitcom/codeception-testrail

# Codeception TestLink Integration Module

This [Codeception](https://codeception.com) extension provides functionality for tests to report results to
[TestLink](http://testlink.org/) using the [TestLink Rest API v2](https://github.com/TestLinkOpenSourceTRMS/testlink-code/tree/testlink_1_9/lib/api/rest/v2).

**Note:** The extension currently only supports the `Cest` Codeception test format.  It cannot report PHPUnit or `Cept`
tests.

## Installation

The easiest way to install this plugin is using [Composer](https://getcomposer.org/).  You can install module
by running:

```
composer require --dev oneko/codeception-testlink
```

## Theory of Operation
TODO

## Configuration

The extension requires four configuration parameters to be set (`user`, `apikey`, `project`).  There are additional
configuration options for overriding statuses and disabling the connection to TestLink.

To enable the extension the following can be added to your `codeception.yml` config file:

```yaml
extensions:
    enabled:
        - OnekO\Codeception\TestLink\Extension
```

Global configuration options (like the `user` and `apikey`) should also be set in the `codeception.yml` config:

```yaml
extensions:
    config:
        OnekO\Codeception\TestLink\Extension:
            enabled: false                    # When false, don't communicate with TestLink (optional; default: true)
            user: 'testlink@oneko.org'   # A TestLink user (required)
            apikey: 'REDACTED'                # A TestLink API Key (required)
	  		url: 'https://myurl.testlink.com' # The base URL for you TestLink Instance
            project: 9                        # TestLink Project ID (required)
            status:
                success: 1                    # Override the default success status (optional)
                skipped: 11                   # Override the default skipped status (optional)
                incomplete: 12                # Override the default incomplete status (optional)
                failed: 5                     # Override the default failed status (optional)
                error: 5                      # Override the default error status (optional)
```

## More Information

* [Codeception](https://codeception.com)
* [TestLink](https://http://testlink.org/)
* [TestLink API](https://github.com/TestLinkOpenSourceTRMS/testlink-code/tree/testlink_1_9/lib/api/rest/v2)
* [TestLink API Authentication](https://docs.google.com/document/d/12jp8pVrgCFdH90S2FLvz6iXfePLZbr11Tv1PqYAXwiA/edit#)

## License

MIT

(c) OnekO https://oneko.org 2018

