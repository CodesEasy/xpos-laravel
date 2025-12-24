# XPOS for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/codeseasy/xpos-laravel.svg?style=flat-square)](https://packagist.org/packages/codeseasy/xpos-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/codeseasy/xpos-laravel.svg?style=flat-square)](https://packagist.org/packages/codeseasy/xpos-laravel)
[![License](https://img.shields.io/packagist/l/codeseasy/xpos-laravel.svg?style=flat-square)](https://packagist.org/packages/codeseasy/xpos-laravel)

Instant public URLs for Laravel development. Share your local Laravel app with a single command.

🌐 **[xpos.dev](https://xpos.dev)**

## Features

- **One Command Setup** - Get a public URL instantly with `php artisan xpos`
- **Auto-Start Server** - Automatically starts `php artisan serve` if not running
- **Secure HTTPS Tunnels** - All traffic is encrypted via SSH
- **Zero Configuration** - Works out of the box, configure only if needed
- **Auto-Proxy Detection** - Automatically configures TrustProxies for correct HTTPS URLs
- **Laravel 10, 11, 12** - Full support for modern Laravel versions

## Use Cases

- 🔗 Test webhooks from third-party services (Stripe, GitHub, etc.)
- 📱 Test your app on mobile devices
- 👥 Share work-in-progress with clients or team members
- 🌐 Demo your local app without deploying
- 🔧 Debug integrations requiring public callbacks

## Installation

```bash
composer require codeseasy/xpos-laravel --dev
```

## Usage

```bash
php artisan xpos
```

That's it! The command will:
1. Start `php artisan serve` if not already running
2. Create an SSH tunnel to XPOS
3. Display your public URL (e.g., `https://abc123.xpos.to`)

## Options

```bash
# Use a specific port
php artisan xpos --port=8080

# Use an existing server (don't start artisan serve)
php artisan xpos --no-serve --port=8000

# Bind to a specific host
php artisan xpos --host=0.0.0.0
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=xpos-config
```

Config options in `config/xpos.php`:

```php
return [
    'server' => 'go.xpos.dev',    // XPOS server
    'ssh_port' => 443,            // SSH port
    'default_port' => 8000,       // Default dev server port
    'trust_proxies' => true,      // Auto-configure HTTPS
];
```

## HTTPS Support

The package automatically configures Laravel's TrustProxies middleware so that `asset()`, `url()`, and other helpers generate HTTPS URLs when accessed through an XPOS tunnel.

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12
- SSH client available in PATH

## .gitignore

Add `.xpos.pid` to your project's `.gitignore`:

```
.xpos.pid
```

## How It Works

1. **Serve Detection**: Uses a `.xpos.pid` file to track which port belongs to your project
2. **SSH Tunnel**: Connects to XPOS using `ssh -p 443 -R0:127.0.0.1:PORT x@go.xpos.dev`
3. **HTTPS**: Auto-configures TrustProxies so assets load correctly

## Troubleshooting

**Port already in use?**
```bash
# Use a different port
php artisan xpos --port=8001
```

**SSH connection issues?**
- Ensure SSH client is installed and available in PATH
- Check firewall settings for port 443

**HTTPS URLs not working?**
- Make sure `trust_proxies` is set to `true` in `config/xpos.php`
- Clear config cache: `php artisan config:clear`

## Security

This package is intended for **development use only**. Do not use in production environments. All traffic goes through XPOS servers via encrypted SSH tunnels.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Support

- **Documentation**: [https://xpos.dev](https://xpos.dev)
- **Issues**: [GitHub Issues](https://github.com/codeseasy/xpos-laravel/issues)
- **Website**: [https://codeseasy.com](https://codeseasy.com)
- **Email**: hello@codeseasy.com

## Credits

- [CodesEasy](https://github.com/codeseasy)
- [All Contributors](../../contributors)

## License

MIT License. See [LICENSE](LICENSE) for details.
