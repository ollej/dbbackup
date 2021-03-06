DbBackup PHP Class
==================

A PHP class to make backups of MySQL databases.

Requirements
------------
 * PHP5
 * MySQL
 * mysqldump program on server.
 * bzip2 or gzip programs on server to use compression.

TODO
----
The following are possible additions for the future.

 * Possibility to select how many files to save.
 * Possibility to have dump emailed as well.
 * Possibility to have dump sent via ftp.
 * Possibility to dump several databases.
 * Possibility to dump specific db tables.
 * Configurable mysqldump options.
 * Possibly add feature to dump without mysqldump.
 * Dump as CSV file. 
 
Usage
-----
Edit the file and change the parameters to the DbBackup constructor:

    $backup = new DbBackup("dbuser", "dbpassword", "dbname", 'secret', "/server/path/to/backups", "localhost", "your_email_address@example.com", DbBackup::COMPRESS_BZIP);

Upload the script to your web account.

Point your browser to the URL of the script, with your password as a parameter:

    http://www.example.com/dbbackup.php?pass=secret

Compression
-----------
If you want the backup file to be compressed, use either DbBackup::COMPRESS_BZIP for 
bzip2 compression, or DbBackup::COMPRESS_GZIP for gzip compression. Otherwise the dump 
file will be saved in plain text.
