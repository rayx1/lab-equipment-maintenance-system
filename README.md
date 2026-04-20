 (PHP + MySQL)

This folder is a direct-deploy backend for typical cPanel hosting environments.

## Files

- `lab_maintenance.sql` -> import this in phpMyAdmin
- `config.php` -> put DB credentials directly here (no `.env` needed)
- `index.php` -> single entry point
- `.htaccess` -> rewrites requests to `index.php`
- `api/index.php` -> REST API router used by root `index.php`
- `uploads/` -> attachment storage

## Deploy Steps

1. Upload the full `shared-hosting/` folder to your hosting account.
2. Create a MySQL database and user in cPanel.
3. Import `lab_maintenance.sql` using phpMyAdmin.
4. Edit `config.php` and set real DB values.
5. Ensure `uploads/` is writable (`755` or `775` as required by host).
6. Test:
   - `GET /api` should return `{ status: "ok" }`
   - `POST /api/auth/login` with:
     - `email: manager@lab.local`
     - `password: Password@123`

## API Base

- Base URL: `https://your-domain.com/api`
- Auth header: `Authorization: Bearer <token>`

## Notes

- This hosting version uses SHA256 password hashes for simplicity.
- Change all seeded passwords immediately after first login.
