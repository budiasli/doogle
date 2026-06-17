<?php
include("../config.php");
include_once("../classes/UrlRewriter.php");

if(isset($_POST["src"])) 
{
	$canonicalSrc = UrlRewriter::normalize($_POST["src"]);

	$query = $con->prepare("UPDATE cari_images SET broken = 1 WHERE imageUrl=:src");
	$query->bindParam(":src", $canonicalSrc);

	$query->execute();
}
else
	echo "No src passed to page"; //DEBUGGING
?>