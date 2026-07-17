southan/wp-cli
==============

Extension pack for developing with WP-CLI & remote WordPress.

## Fluent shell API

Easily compose and handle system commands.

```php
$zip = new WP_CLI\Shell( 'zip' );

// Add command options & arguments
$zip->add( [ 'recurse-paths' => true, '-q' => true ], 'archive.zip', '.' );

// Set working directory
$zip->cwd( __DIR__ . '/src' );

// Set environment variables
$zip->env([
    'ZIPOPT' => '-D',
]);

// Exit on failure
$zip->fail();

// Throw exception on failure
$zip->throw();

// Print warning on failure
$zip->warn();

// Chainable
$zip->add( 'archive.zip', '.' )
    ->cwd( __DIR__ . '/src' )
    ->env([
        'ZIPOPT' => '-FS'
    ])
    ->fail();

// Get command output
$out = $zip->get();

// Get command error output
$error = $zip->get_error();

// Stream command output instead (non-capturing)
$zip->stream();

// Command exit code was zero
$is_ok = $zip->is_ok();

// Check command exit code
if ( $shell->is( 12 ) ) {
    WP_CLI::warning( 'Nothing to ZIP.' );
}
```

## Commander

Universal API for both system and WP-CLI commands.

```php
$commander = new WP_CLI\Commander(
    // Set debug group for all commands
    debug: 'my_debug',

    // Set default failure mode (default THROW)
    mode: WP_CLI\Shell::EXIT,

    // Set WP-CLI runtime config (default is current runtime)
    wp_config: [
        'path' => '/path/to/another/wordpress',
    ],
);

// System shell
$home = $commander->sh( 'echo $HOME' )->get();

// WP-CLI shell
$table_prefix = $commander->wp( 'config get table_prefix' )->get();
```

## Remote

Run system & WP-CLI commands on remote WordPress installations.

```php
// Get remote control for WordPress on filesystem
$remote = WP_CLI\Remote::create( '/path/to/wordpress' );

// Get remote control for WordPress over SSH
$remote = WP_CLI\Remote::create( 'user@host' );

// Get remote control for WP-CLI alias
$remote = WP_CLI\Remote::create( '@staging' );

// Get remote controls for all WP-CLI aliases
$remotes = WP_CLI\Remote::resolve( '@all' );

// Get remote controls for WP-CLI alias group "@both"
$remotes = WP_CLI\Remote::resolve( '@both' );
```

A remote (control) is an extended `Commander` instance but with some additional methods specific to WordPress.

```php
// Get remote WordPress path (returns path as-is if configured)
$path = $remote->path();

// Get remote WordPress realpath (always execs WP)
$realpath = $remote->get_path();

// Get remote WordPress URL
$url = $remote->get_url();

// Get remote WordPress constant
$cookie_domain = $remote->get_const( 'COOKIE_DOMAIN' );

// Get remote WordPress option
$page_for_posts = $remote->get_option( 'page_for_posts' );

// Eval PHP on remote WordPress
$remote->eval( 'wp_mail( "john@example.com", "Test", "Test" );' )->run();

// Filesystem API
$remote->is_file( 'foo.txt' );
$remote->is_dir( 'foo/' );
$remote->mkdir( 'foo' );
$remote->unlink( 'foo.txt' );
$remote->file_put_contents( 'foo.txt', 'Foo' );

// Copy file from local filesystem to remote
$remote->copy( 'foo.txt', "$remote:foo.txt" );

// Copy file from remote to local filesystem
$remote->copy( "$remote:foo.txt", 'foo.txt' );

// Sync local directory to remote
$remote->rsync( "foo/", "$remote:foo/" )->stream();

// Sync remote directory to local
$remote->rsync( "$remote:foo/", 'foo/' )->stream();
```

## MU Plugin

Install (& uninstall) persistent scripts.

```php
$mu_plugin = new WP_CLI\MU_Plugin(
    name: 'Welcome Notice',
    description: 'Set a welcome notice in the WordPress admin.'
);

$code = <<<PHP
add_action( 'admin_notices', function () {
    wp_admin_notice( 'Welcome to WordPress!' );
});
PHP;

$mu_plugin->install( $code );

// ... or delete
$mu_plugin->delete();

// ... or install on a remote WordPress
$mu_plugin->install( $code, $remote ) ;

// ... or delete on a remote WordPress
$mu_plugin->delete( $remote );
```

## Contributing

We appreciate you taking the initiative to contribute to this project.

Contributing isn’t limited to just code. We encourage you to contribute in the way that best fits your abilities, by writing tutorials, giving a demo at your local meetup, helping other users with their support questions, or revising our documentation.

For a more thorough introduction, [check out WP-CLI's guide to contributing](https://make.wordpress.org/cli/handbook/contributing/). This package follows those policy and guidelines.

### Reporting a bug

Think you’ve found a bug? We’d love for you to help us get it fixed.

Before you create a new issue, you should [search existing issues](https://github.com/southan/wp-cli/issues?q=label%3Abug%20) to see if there’s an existing resolution to it, or if it’s already been fixed in a newer version.

Once you’ve done a bit of searching and discovered there isn’t an open or fixed issue for your bug, please [create a new issue](https://github.com/southan/wp-cli/issues/new). Include as much detail as you can, and clear steps to reproduce if possible. For more guidance, [review our bug report documentation](https://make.wordpress.org/cli/handbook/bug-reports/).

### Creating a pull request

Want to contribute a new feature? Please first [open a new issue](https://github.com/southan/wp-cli/issues/new) to discuss whether the feature is a good fit for the project.

Once you've decided to commit the time to seeing your pull request through, [please follow our guidelines for creating a pull request](https://make.wordpress.org/cli/handbook/pull-requests/) to make sure it's a pleasant experience. See "[Setting up](https://make.wordpress.org/cli/handbook/pull-requests/#setting-up)" for details specific to working on this package locally.
