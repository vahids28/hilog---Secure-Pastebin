# hilog - Secure Pastebin

> Simple, secure, and modern pastebin with database support, password protection, and brute force prevention.

## ✨ Features

- 🔐 **AES-256-GCM** encryption for password-protected pastes
- 🛡️ **Brute force protection** - 10 attempts per paste, 30 per IP/hour, 15min auto-reset
- 🔗 **Clean URLs** - only lowercase letters (a-z), 4-6 characters, case-insensitive
- ⏰ **Expiration options** - from 1 hour to permanent
- 👁️ **View tracking** - counts views and failed password attempts
- 🗑️ **Instant deletion** - delete pastes after viewing
- 📱 **Fully responsive** - works on all devices
- 👨‍💻 **Admin panel** - manage all pastes, change credentials

## 🚀 Quick Install

1. Upload files to server
2. Run `install.php`
3. Enter database details and admin password
4. Delete `install.php` after installation

## 📁 Files

- `index.php` - Main application (v20.2)
- `admin.php` - Admin panel (v3.1)  
- `install.php` - Installation script
- `config.php` - Database config (auto-generated)
- `style.css` - Main styles
- `admin-style.css` - Admin styles
- `.htaccess` - URL routing

## 🔧 Requirements

- PHP 7.4+
- MySQL 5.7+
- OpenSSL extension
- mod_rewrite (Apache)

## 🌐 URLs

- Home: `https://domain.com/`
- Paste: `https://domain.com/abcde`
- Admin: `https://domain.com/admin.php`

## 🔒 Default Admin

- Username: `admin`
- Password: set during installation
- Change in Admin Panel → Settings

## 📝 License

MIT
