<?php

register_activation_hook(__FILE__,'wdm_create_bidders_table');
function wdm_create_bidders_table()
{
   require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
   global $wpdb;
   
   $data_table = $wpdb->prefix."wdm_bidders";
   $sql = "CREATE TABLE IF NOT EXISTS $data_table
  (
   id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
   name VARCHAR(45),
   email VARCHAR(45),
   auction_id BIGINT(20),
   bid DECIMAL(10,2),
   date datetime,
   PRIMARY KEY (id)
  );";
  
   dbDelta($sql);
   $indx = $wpdb->get_results("SHOW indexes FROM $data_table WHERE Column_name = 'bid';");
    for ($i = 2; $i <= count($indx); $i++) {
        $alt_sql = "ALTER TABLE $data_table DROP INDEX bid_".$i.';';
        $wpdb->query($alt_sql);
    }
function cancel_last_bid_callback()
{
    global $wpdb;
    
    $cancel_bid = $wpdb->query( 
	$wpdb->prepare( 
		"
                DELETE FROM ".$wpdb->prefix."wdm_bidders
		 WHERE id = %d
		",
	        $_POST['cancel_id']
        )
    );
    
   if($cancel_bid)
        printf(__("Bid entry of %s was removed successfully.", "wdm-ultimate-auction"), $_POST['bidder_name']);
    else
        _e("Sorry, bid entry cannot be removed.", "wdm-ultimate-auction");
        
    die();
}
function place_bid_now_callback()
{
   $ab_bid=round((double)$_POST['ab_bid'],2);
   $check=get_option('wdm_users_login');
   $flag=false;
   if($check=='with_login' && !is_user_logged_in())
   {
      echo json_encode(array("stat" => "Please log in to place bid"));
      die();
   }
   else if($check=="without_login" || is_user_logged_in())
   {
      $flag=true;
   }
   if($flag){
    global $wpdb;
    $wpdb->hide_errors();
    
    $q="SELECT MAX(bid) FROM ".$wpdb->prefix."wdm_bidders WHERE auction_id =".$_POST['auction_id'];
    $next_bid = $wpdb->get_var($q);
    
    if(!empty($next_bid)){
      update_post_meta($_POST['auction_id'], 'wdm_previous_bid_value', $next_bid); //store bid value of the most recent bidder
      $first_bid = 1;
    }
    
   
   if(empty($next_bid)){
      $next_bid = get_post_meta($_POST['auction_id'], 'wdm_opening_bid', true);
      $first_bid = 0;
   }
      
    $high_bid = $next_bid;

    if ($first_bid == 1) {   
      $next_bid = $next_bid + get_post_meta($_POST['auction_id'],'wdm_incremental_val',true);
  }
    
    $terms = wp_get_post_terms($_POST['auction_id'], 'auction-status',array("fields" => "names"));
   
   $next_bid=round($next_bid,2);
   
    if($ab_bid < $next_bid)
    {
      echo json_encode(array('stat' => 'inv_bid', 'bid' => $next_bid));
    }
    elseif(in_array('expired',$terms))
    {
      echo json_encode(array("stat" => "Expired"));
    }
    else
    {
         $ab_name = $_POST['ab_name'];
         $ab_email = $_POST['ab_email'];
	 
         $ab_bid = apply_filters('wdm_ua_modified_bid_amt', $ab_bid, $high_bid, $_POST['auction_id']);
         
         $a_bid = array();
         
         if(is_array($ab_bid)){
            $a_bid = $ab_bid;
            if(!empty($a_bid['abid'])){
               $ab_bid = $a_bid['abid'];
            }
            
            if(!empty($a_bid['cbid'])){
               $cu_bid = $a_bid['cbid'];
            }
            
            if(!empty($a_bid['name'])){
               $ab_name = $a_bid['name'];
            }
            
            if(!empty($a_bid['email'])){
               $ab_email = $a_bid['email'];
            }
         }
         
         $buy_price = get_post_meta($_POST['auction_id'], 'wdm_buy_it_now', true);
         
         if(!empty($buy_price) && $ab_bid >= $buy_price){
            add_post_meta($_POST['auction_id'], 'wdm_this_auction_winner', $ab_email, true);
            
            if(get_post_meta($_POST['auction_id'], 'wdm_this_auction_winner', true) === $ab_email){
               if(!empty($a_bid)){
            do_action('wdm_ua_modified_bid_place', array( 'email_type' => 'winner', 'mod_name' => $ab_name, 'mod_email' => $ab_email, 'mod_bid' => $ab_bid, 'orig_bid' => $cu_bid, 'orig_name' => $_POST['ab_name'], 'orig_email' => $_POST['ab_email'], 'auc_name' => $_POST['auc_name'], 'auc_desc' => $_POST['auc_desc'], 'auc_url' => $_POST['auc_url'], 'site_char' => $_POST['ab_char'], 'auc_id' => $_POST['auction_id']));
            }
            else{
               $place_bid = $wpdb->insert( 
	$wpdb->prefix.'wdm_bidders', 
	array( 
		'name' => $ab_name, 
		'email' => $ab_email,
                'auction_id' => $_POST['auction_id'],
                'bid' => $ab_bid,
                'date' => date("Y-m-d H:i:s", time())
	), 
	array( 
		'%s', 
		'%s',
                '%d',
                '%f',
                '%s'
	) 
        );
               
            if($place_bid){
		     update_post_meta($_POST['auction_id'], 'wdm_listing_ends', date("Y-m-d H:i:s", time()));
		     $check_term = term_exists('expired', 'auction-status');
		     wp_set_post_terms($_POST['auction_id'], $check_term["term_id"], 'auction-status');
                     update_post_meta($_POST['auction_id'], 'email_sent_imd', 'sent_imd');
            
                     echo json_encode(array('type' => 'simple', 'stat' => 'Won', 'bid' => $ab_bid));
               }
            }   
            }
            else{
                  echo json_encode(array("stat" => "Sold"));
            }
         }
         else{
            
            //$args = array();
            if(!empty($a_bid)){
            do_action('wdm_ua_modified_bid_place', array( 'mod_name' => $ab_name, 'mod_email' => $ab_email, 'mod_bid' => $ab_bid, 'orig_bid' => $cu_bid, 'orig_name' => $_POST['ab_name'], 'orig_email' => $_POST['ab_email'], 'auc_name' => $_POST['auc_name'], 'auc_desc' => $_POST['auc_desc'], 'auc_url' => $_POST['auc_url'], 'site_char' => $_POST['ab_char'], 'auc_id' => $_POST['auction_id']));
            } 
        else{
               do_action('wdm_extend_auction_time', $_POST['auction_id']);
               
               $place_bid = $wpdb->insert( 
               $wpdb->prefix.'wdm_bidders', 
               array( 
		'name' => $ab_name, 
		'email' => $ab_email,
                'auction_id' => $_POST['auction_id'],
                'bid' => $ab_bid,
                'date' => date("Y-m-d H:i:s", time())
            ), 
               array( 
		'%s', 
		'%s',
                '%d',
                '%f',
                '%s'
            ) 
            );
                     
            if($place_bid){
               echo json_encode(array('type' => 'simple', 'stat' => 'Placed', 'bid' => $ab_bid));
            }
        }
         }
    }
}
else{
   echo json_encode(array("stat" => "Please log in to place bid"));
}
	die();
}
function wdm_ending_time_calculator($seconds)
{
   $days = floor($seconds / 86400);
   $seconds %= 86400;

   $hours = floor($seconds / 3600);
   $seconds %= 3600;

   $minutes = floor($seconds / 60);
   $seconds %= 60;
					
   $rem_tm = "";
					

   if($days == 1 || $days == -1)
      $rem_tm = "<span class='wdm_datetime' id='wdm_days'>".$days."</span><span id='wdm_days_text'> ".__('day', 'wdm-ultimate-auction')." </span>";
   elseif($days == 0)
      $rem_tm = "<span class='wdm_datetime' id='wdm_days' style='display:none;'>".$days."</span><span id='wdm_days_text'></span>";
   else
      $rem_tm = "<span class='wdm_datetime' id='wdm_days'>".$days."</span><span id='wdm_days_text'> ".__('days', 'wdm-ultimate-auction')." </span>";
   
   if($hours == 1 || $hours == -1)
      $rem_tm .= "<span class='wdm_datetime' id='wdm_hours'>".$hours."</span><span id='wdm_hrs_text'> ".__('hour', 'wdm-ultimate-auction')." </span>";
   elseif($hours == 0)
      $rem_tm .= "<span class='wdm_datetime' id='wdm_hours' style='display:none;'>".$hours."</span><span id='wdm_hrs_text'></span>";
   else 
      $rem_tm .= "<span class='wdm_datetime' id='wdm_hours'>".$hours."</span><span id='wdm_hrs_text'> ".__('hours', 'wdm-ultimate-auction')." </span>";

   if($minutes == 1 || $minutes == -1)
      $rem_tm .= "<span class='wdm_datetime' id='wdm_minutes'>".$minutes."</span><span id='wdm_mins_text'> ".__('minute', 'wdm-ultimate-auction')." </span>";
   elseif($minutes == 0)
      $rem_tm .= "<span class='wdm_datetime' id='wdm_minutes' style='display:none;'>".$minutes."</span><span id='wdm_mins_text'></span>"; 
   else
      $rem_tm .= "<span class='wdm_datetime' id='wdm_minutes'>".$minutes."</span><span id='wdm_mins_text'> ".__('minutes', 'wdm-ultimate-auction')." </span>";

   if($seconds == 1 || $seconds == -1)
      $rem_tm .= "<span class='wdm_datetime' id='wdm_seconds'>".$seconds."</span><span id='wdm_secs_text'> ".__('second', 'wdm-ultimate-auction')."</span>";
   elseif($seconds == 0)
      $rem_tm .= "<span class='wdm_datetime' id='wdm_seconds' style='display:none;'>".$seconds."</span><span id='wdm_secs_text'></span>";
   else
      $rem_tm .= "<span class='wdm_datetime' id='wdm_seconds'>".$seconds."</span><span id='wdm_secs_text'> ".__('seconds', 'wdm-ultimate-auction')."</span>";
      
      return $rem_tm;
}
}
?>
