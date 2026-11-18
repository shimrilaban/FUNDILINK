# FundiLink on XAMPP

This project uses PHP and MySQL through XAMPP. The browser page reads and writes data through `api/index.php`; listings, jobs, bookings, and reviews are stored in MySQL rather than browser storage. Customer and fundi accounts use PHP sessions, password hashes, and CSRF tokens. Booking reads and status updates are scoped to the signed-in account and its role.

## Run locally

1. Copy the project folder into XAMPP's `htdocs` directory, for example `C:\xampp\htdocs\FundiLink`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. For a new install, import `database.sql` through phpMyAdmin; it creates the `fundilink_marketplace` database and sample listings. For an existing prototype database, import `database_upgrade.sql` once to add accounts and booking ownership columns without deleting existing rows.
4. For an existing account-enabled database, import `database_admin_upgrade.sql` once to enable admin accounts and account deactivation, then import `database_email_upgrade.sql` once to enable email sign-in.
5. In PowerShell, run `.\tools\create-admin.ps1` and follow the prompts. The password is entered without echo and is never stored in the project. This creates the first active admin when none exists.
6. If your MySQL credentials differ from XAMPP's defaults (`root` with an empty password), set `FUNDILINK_DB_HOST`, `FUNDILINK_DB_NAME`, `FUNDILINK_DB_USER`, and `FUNDILINK_DB_PASSWORD` in Apache's environment and for the setup command. Defaults are in `api/config.php`; this app uses a separate `fundilink_marketplace` database so it won't conflict with the attached account-system schema.
7. Visit `http://localhost/FundiLink/`, sign in with the admin email/password, and open **Admin**.

Do not open the HTML file directly from File Explorer: PHP and the API need to be served by Apache. The API requires PHP with PDO MySQL enabled.

## API routes

- `GET api/index.php?resource=bootstrap` — workers, open jobs, bookings, and reviews.
- `POST api/index.php?resource=auth-register` / `auth-login` — create or sign in to a customer or fundi account.
- `POST api/index.php?resource=auth-logout` — end the current session.
- `GET api/index.php?resource=admin-users` — list accounts (admin only).
- `PATCH api/index.php?resource=admin-users&id=ID` — deactivate or reactivate an account (admin only).
- `GET api/index.php?resource=admin-dashboard` — site counts and recent activity (admin only).
- `PATCH api/index.php?resource=admin-jobs&id=ID` — close or reopen a job post (admin only).
- `GET api/index.php?resource=bookings` — bookings visible to the signed-in customer or fundi.
- `POST api/index.php?resource=workers` — create a fundi profile.
- `POST api/index.php?resource=jobs` — post a job.
- `POST api/index.php?resource=bookings` — request a booking.
- `PATCH api/index.php?resource=bookings&id=ID` — update booking status.
- `POST api/index.php?resource=reviews` — review a completed booking.

Worker photo/ID uploads and phone-number verification are not implemented. Do not expose the prototype publicly until phone ownership verification and production security controls are added.

Account deactivation is reversible and preserves bookings/reviews. The final active administrator and the currently signed-in administrator cannot be deactivated.
