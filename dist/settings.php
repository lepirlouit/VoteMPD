<?php

/* For errorreporting. Can be removed */
ini_set("error_reporting", E_ALL);
ini_set("log_errors", 1);
ini_set("error_log", "logs/votempd.txt");

/* mysql database connection */
$db_server="localhost";
$db_port="3306";
$db_user="root";
$db_pass="";
$db_database="votempd";

/* music folder */
$GLOBALS["path"]=""; // without trailing "/"
$GLOBALS["pathplaylists"]=""; // without trailing "/"; Folder inside $GLOBALS["path"]
$GLOBALS["pathuploads"]=""; // without trailing "/"; Folder inside $GLOBALS["path"]
$GLOBALS["defaultplaylist"]=""; // playlist to use if highscore is empty
$GLOBALS["voteskipcount"]=2; // if more than x user vote for skip => skip

/* MPD connection */
$GLOBALS["mpdport"]=6600;
$GLOBALS["mpdip"]="localhost";

/* PDO */
if(phpversion()=="5.3.3-7+squeeze19")
$GLOBALS["db"] = new PDO('mysql:host='.$db_server.';dbname='.$db_database.';', $db_user, $db_pass,array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"));
else
$GLOBALS["db"] = new PDO('mysql:host='.$db_server.';port='.$db_port.';dbname='.$db_database.';charset=UTF8', $db_user, $db_pass);

?>