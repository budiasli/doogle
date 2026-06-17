<?php
include("../config.php");

if(isset($_POST["imageUrl"])) 
{
	$query = $con->prepare("UPDATE cari_images SET clicks = clicks + 1 WHERE imageUrl=:imageUrl");
	$query->bindParam(":imageUrl", $_POST["imageUrl"]);

	$query->execute();
}
else
	echo "No image URL passed to page"; //DEBUGGING
?>