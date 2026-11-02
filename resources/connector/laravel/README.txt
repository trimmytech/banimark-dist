Banimark connector for Laravel - where each file goes

1. app/Http/Controllers/BanimarkConnectorController.php  ->  your app's app/Http/Controllers/
2. routes/banimark-connector.php                          ->  your app's routes/
3. Add this one line at the bottom of routes/api.php:

       require __DIR__.'/banimark-connector.php';

   (Laravel 11 or newer: if routes/api.php does not exist, run `php artisan install:api` first.)
4. Open https://your-site.example/api/banimark-connector in a browser. It says "Banimark connector
   is installed" and shows the address - paste that address into Banimark -> Tools -> "Where your data lives".

The secret is already inside the controller; it matches the desk you downloaded this from. The connector
uses your app's default database connection, reads only (single SELECTs, 100 rows, 5 seconds), and never
lists or returns columns that look like secrets. Nothing else to configure.
