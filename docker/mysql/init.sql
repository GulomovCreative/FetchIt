-- modx3 comes from MYSQL_DATABASE; the MODX 2 site needs its own schema.
CREATE DATABASE IF NOT EXISTS `modx2` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
GRANT ALL PRIVILEGES ON `modx2`.* TO 'modx'@'%';
GRANT ALL PRIVILEGES ON `modx3`.* TO 'modx'@'%';
FLUSH PRIVILEGES;
