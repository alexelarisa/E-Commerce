<?php


//CREDENTIALS FOR DATABASE
define ('DBSERVER', 'localhost');
define ('DBUSER', 'id7594142_wp_cab192df6281e53a72e8a2d795038736');
define ('DBPASS','descopera1');
define ('DBNAME','id7594142_wp_cab192df6281e53a72e8a2d795038736');

//LET'S INITIATE CONNECT TO DB
$connection = mysql_connect(DBSERVER, DBUSER, DBPASS) or die("Can't connect to server. Please check credentials and try again");
$result = mysql_select_db(DBNAME) or die("Can't select database. Please check DB name and try again");

//SELECT FOR PRODUCT RATING
public function select(){
	$select = "SELECT * FROM `product_rating` ";
	$result = mysqli_query($this->db ,$select);
	return mysqli_fetch_all($result);
}

//UPDATE PRODUCT RATING
public function update($id, $rating) {
$update = "UPDATE `product_rating` SET rating = '$rating' WHERE id = '$id' ";
$result = mysqli_query($this->db ,$update);
if($result) { 
	return 'Data Updated Successfully';
}
?>