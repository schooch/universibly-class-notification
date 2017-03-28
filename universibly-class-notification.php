<?php
/*
Plugin Name: Universibly Booking Email Notification
Plugin URI: http://universibly.com
Description: Simple non-bloated WordPress Contact Form
Version: 1.0
Author: Ian Lainchbury
Author URI: http://ianlainchbury.com
*/

require_once ABSPATH.'wp-content/plugins/un-class-notification/lib/ics.php';
require_once ABSPATH.'wp-content/plugins/un-class-notification/templates/emails/email-header.php';
require_once ABSPATH.'wp-content/plugins/un-class-notification/templates/emails/email-footer.php';

// Set the mail type to html
apply_filters( 'wp_mail_content_type', 'text/html' );

//* Confirmation from woo commerce
add_action( 'woocommerce_payment_complete', 'universibly_payment_complete' );
//add_action( 'test_payment_complete', 'universibly_payment_complete' );
function universibly_payment_complete( $order_id ){
    global $wpdb;
    $row = $wpdb->get_row(@$wpdb->prepare('SELECT * FROM '.$wpdb->prefix . 'virtualclassroom_settings'));
    if(!$row)
    {
        echo 'Please setup API key and URL';
        return;
    }

    $key = $row->braincert_api_key;
    $base_url = $row->braincert_base_url;

    $order = wc_get_order( $order_id );
    $current_user = wp_get_current_user();

    // Schedule lession
    foreach( $order->get_items() as $item_id => $item ) {
        $sold_by =  WC_Product_Vendors_Utils::get_sold_by_link( $item['product_id'] );

        $vendor = get_user_by( 'login',  str_replace('_vendor', '', $sold_by['name']));

        // Class details
        $date = new DateTime($item['Booking Date']);
        $day = $date->format('D');
        $date = $date->format('Y-m-d');
        
        $start_timestamp = strtotime($item['Booking Time']);
        $start_time = date('h:iA', $start_timestamp);

        $end_timestamp = $start_timestamp + 3600;
        $end_time = date('h:iA', $end_timestamp);

        $class = braincert_schedule_class($date, $start_time, $end_time, $item['name'], $key, $base_url);

        // Get Student class url
        $student_url = braincert_class_details($class['id'], $class['title'], $key, $base_url, $current_user, 0);

        // Get Mentor class url
        $mentor_url = braincert_class_details($class['id'], $class['title'], $key, $base_url, $vendor , 1);

        // Prep calendar attachment ics file
        $ics = new ICS(array(
          'description' => 'Follow this link to start your lesson: '.$student_url,
          'dtstart' => $date.' '.$start_time,
          'dtend' => $date.' '.$end_time,
          'summary' => $item['name']
        ));

        $ics_message = $ics->to_string();
        $ics_file = ABSPATH.'wp-content/plugins/un-class-notification/lib/class.ics';

        $myfile = fopen( $ics_file , "w") or die(print_r(error_get_last(),true));
        $txt = $ics_message;
        fwrite($myfile, $txt);
        fclose($myfile);

        $attachments = array( $ics_file );

        $headers = array('Content-Type: text/html; charset=UTF-8');

        $student_message = $mentor_message = '';

        // Email Student
        $student_subject = 'Your lesson at '.$item['Booking Time'].' on '.$day.' '.$item['Booking Date'].' has been booked';

        $student_message .= universibly_get_email_header( 'Class Booked' );
        $student_message .= '<p><a href="'.$student_url.'">Click here to start your lesson</a><br />Please enter the class 5 minutes before the lesson is due to begin.</p>';
        $student_message .= universibly_get_email_footer();

        // Email Mentor
        $mentor_subject = 'You have a booking at '.$item['Booking Time'].' on '.$day.' '.$item['Booking Date'];
        
        if ( wp_mail( $current_user->user_email, $student_subject, $student_message, $headers, $attachments ) ) {
            // Student email sent successfully
        } else {
            echo 'An unexpected error occurred: Student email';
        }

        $mentor_message .= universibly_get_email_header( 'New Booking' );
        $mentor_message .= '<p><a href="'.$mentor_url.'">Click here to start your lesson</a><br />Please enter the class 5 minutes before the lesson is due to begin.</p>';
        $mentor_message .= universibly_get_email_footer();
        
        if ( wp_mail( $vendor->user_email, $mentor_subject, $mentor_message, $headers, $attachments ) ) {
            // Mentor eail sent successfully
        } else {
            echo 'An unexpected error occurred: Vendor email';
        }
    }
}

function braincert_schedule_class($date, $start, $end, $name, $key, $base_url){
    // Schedule class
    $data = [];
    $classes = [];
    $data['task'] = sanitize_text_field('schedule');
    $data['apikey'] = sanitize_text_field($key);

    $data['title'] = $name;
    $data['timezone'] = 28;
    $data['date'] = $date;
    $data['start_time'] = $start;
    $data['end_time'] = $end; 
    $data['record'] = 2; 
    
    $data_string = http_build_query($data);
    
    $ch = curl_init($base_url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $result = curl_exec($ch);
    $result_data =  json_decode($result);
     
    if($result_data->status == 'error'){
        $msg = $result_data->error;
        echo $msg;
    }
    
    if($result_data->Status == 'OK'){ 
        $class['id'] = $result_data->class_id;
        $class['title'] = $result_data->title;
        $class['start_time'] = $start_time;
        $class['end_time'] = $end_time;
        $class['date'] = $date;
    }

    return $class;
}

function braincert_class_details($class_id, $class_name, $key, $base_url, $user, $is_teacher){
    $data['task'] = sanitize_text_field('getclasslaunch');
    $data['apikey'] = sanitize_text_field($key);

    $data['class_id'] = sanitize_text_field($class_id);
    $data['userId'] = sanitize_text_field($user->ID);
    $data['userName'] = sanitize_text_field($user->user_login);
    $data['isTeacher'] = sanitize_text_field($is_teacher);
    $data['lessonName'] = sanitize_text_field($class_name);
    $data['courseName'] = sanitize_text_field($class_name);

    $data_string = http_build_query($data);
    
    $ch = curl_init($base_url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $result = curl_exec($ch);
    $result_data =  json_decode($result);


    if($result_data->status == 'error'){
        $msg = $result_data->error;
        echo $msg;
    }
    
    $email_text = '';
    if($result_data->status == 'ok'){       
        // Send email to student
        $email_text .= $result_data->encryptedlaunchurl;
    }

    return $email_text;
}

?>