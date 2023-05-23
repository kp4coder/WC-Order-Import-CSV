<?php
include("wp-load.php");
echo "<pre>";

$csv_file = 'orders.csv';
if (($handle = fopen($csv_file, "r")) !== FALSE) {
    $headers = fgetcsv($handle); // Skip the headers row

    $all_orders = array();
    while (($data = fgetcsv($handle)) !== FALSE) {
        $order_data = array();
        foreach ($headers as $index => $header) {
            $order_data[$header] = $data[$index];
        }

        $product_query = new WP_Query( array(
            'post_type'      => 'product',
            'posts_per_page' => 1,
            'meta_query'     => array(
                array(
                    'key'   => '_sku',
                    'value' => $order_data['SKU']
                ),
            ),
        ) );
        $product_id = '';
        if( !empty($product_query->post) ) {
            $product_id = $product_query->post->ID;
        }


        if( isset($all_orders[$order_data['Order no.']]) && !empty($all_orders[$order_data['Order no.']]) ) {
            $all_orders[$order_data['Order no.']]['product_ids'][$product_id] = $order_data['Qty'];
        } else {
            $order_data['product_ids'][$product_id] = $order_data['Qty'];
            $all_orders[$order_data['Order no.']] = $order_data;
        }
    }
    fclose($handle);

    $completed_orders = get_option("count_orders", 0);
    $inserted_orders = 0;
    $count = 0;
    foreach( $all_orders as $order ) {
        $count++;
        if( $completed_orders < $count ) {
            if( $inserted_orders < 10 ) {
                add_order($order);
                $completed_orders++;
                $inserted_orders++;
                update_option( "count_orders", $completed_orders );
            }
        } 
    }
}

function add_order($order_data) {

    // Extract order details from CSV row
    $order_number = $order_data['Order no.'];
    $order_datetime = strtotime($order_data['Date'].' '.$order_data['Time']);

    // Create a new empty order
    $order = wc_create_order();

    // Set order date and time
    $order->set_date_created($order_datetime);

    // Set customer ID for the order
    $user = get_user_by('email', $order_data['Contact email']);
    $first_name = $order_data['Recipient name'];
    $last_name = '';
    if ($user) {
        $user_id = $user->ID;
        $order->set_customer_id($user_id);
        $first_name = get_user_meta($user_id, 'first_name', true);
        $last_name = get_user_meta($user_id, 'last_name', true);
    }

    // Add product to the order
    if( !empty($order_data['product_ids']) ) {
        foreach($order_data['product_ids'] as $product_id => $qty) {
            $product = wc_get_product($product_id);
            $order->add_product($product, $qty);
        }
    }

    // Set billing and shipping addresses
    $billing_address = array(
        'first_name' => $first_name,
        'last_name'  => $last_name,
        'company'    => '',
        'address_1'  => $order_data['Billing address'],
        'address_2'  => '',
        'city'       => $order_data['Billing city'],
        'state'      => $order_data['Billing state'],
        'postcode'   => $order_data['Billing ZIP/postal code'],
        'country'    => $order_data['Billing country'],
        'email'      => $order_data['Contact email'],
        'phone'      => $order_data['Recipient phone no.'],
    );
    $order->set_billing_address( $billing_address );
    
    // Set the shipping address on the order
    $shipping_address = array(
        'first_name' => $first_name,
        'last_name'  => $last_name,
        'company'    => '',
        'address_1'  => $order_data['Delivery address'],
        'address_2'  => '',
        'city'       => $order_data['Delivery city'],
        'state'      => $order_data['Delivery state'],
        'postcode'   => $order_data['Delivery ZIP/postal code'],
        'country'    => $order_data['Delivery country'],
    );
    $order->set_shipping_address( $shipping_address );

    // Set the shipping method
    $shipping_cost_tax = ( $order_data['Shipping rate'] * 20 ) / 100;
    $shipping_cost = $order_data['Shipping rate'] + $shipping_cost_tax;
    $WC_Shipping_Rate = new WC_Shipping_Rate();
    $WC_Shipping_Rate->set_id('flat_rate:9');
    $WC_Shipping_Rate->set_instance_id('9');
    $WC_Shipping_Rate->set_cost($shipping_cost);
    $WC_Shipping_Rate->set_label($order_data['Delivery method'].' - '.$order_data['Delivery time']);
    $WC_Shipping_Rate->set_method_id('9');
    $order->add_shipping($WC_Shipping_Rate);

    // Calculate totals
    $order->calculate_totals();

    // Set the payment method
    $order->set_payment_method($order_data['Payment method']);

    // Set the payment status
    $payment_status = array(
        'Paid' => 'wc-completed',
        'Refunded' => 'wc-refunded',
        'Partially Refunded' => 'wc-refunded',
    );
    $order->set_status($payment_status[$order_data['Payment status']]);

    // Add notes to orders
    if( !empty($order_data['Note from customer']) ) {
        $order->add_order_note($order_data['Note from customer'], true);
    }
    if( !empty($order_data['Additional checkout info']) ) {
        $order->add_order_note($order_data['Additional checkout info'], false);
    }
    if( !empty($order_data['Tracking no.']) ) {
        $order->add_order_note('Tracking No - '.$order_data['Tracking no.'], false);
        $order->add_order_note('Shipping label - '.$order_data['Shipping label'], false);
    }

    // Save the order
    $order->save();

    // Retrieve the order ID
    $order_id = $order->get_id();

    add_post_meta( $order_id, 'wix_order_id', $order_data['Order no.'] );

    echo "<br/>".$order_id;

}

?>