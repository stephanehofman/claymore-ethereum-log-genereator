#!/usr/bin/php
<?php

define('DEBUG_MODE', true);
define('SERVICE_URL', 'http://192.168.1.186');
define('SERVICE_PORT', 3333);
define('RUN_INDEFINITELY', false); // If set to true script will continue to loop indefinitely
define('UPDATE_FREQ', 10); // Scan and generate log every 10s if RUN_INDEFINITELY=true
define('MACHINE_NAME', 'minr');
define('FILE_LOG_GPUS', 'gpu.log');
define('FILE_LOG_TOTALS', 'totals.log');
define('DUAL_CURRENCY', 'DCR');

while(true) {

    $curl_url = SERVICE_URL.':'.SERVICE_PORT;
    $arr_curl_result = fetch_via_curl($curl_url);

    $arr_log_line = array();

    if ($arr_curl_result['quick_status'] == 'ok' && strlen($arr_curl_result['curl_exec']) > 0) {

        $clean = strip_tags($arr_curl_result['curl_exec']);

        $arr_lines = array_reverse(explode("\n", $clean));

        $arr_gpu_info = $arr_totals_info = array();

        $i = 0;
        foreach ($arr_lines as $key => $line) {


            if (substr($line, 0, 4) == 'GPU ') {
                break;
            }

            // Get temp. and fans of individual cards - GPU0 t=57C fan=23%, GPU1 t=67C fan=40%, GPU2 t=64C fan=70%, GPU3 t=73C fan=70%
            if (substr($line, 0, 4) == 'GPU0') {
                $arr_gpu_temp_fan = explode(', ', $line);

                $gpu_number = 0;
                foreach ($arr_gpu_temp_fan as $k_gpu => $gpu_info) {
                    $arr_gpu = explode(' ', $gpu_info);

                    $arr_gpu_info[$gpu_number] = array(
                        'id' => $arr_gpu[0],
                        'temp' => str_replace(array('t=', 'C'), "", $arr_gpu[1]),
                        'fan' => str_replace(array('fan=', '%'), "", $arr_gpu[2]),
                    );

                    $gpu_number++;
                }

            }

            // Get mining speed of individual cards - ETH: GPU0 28.780 Mh/s, GPU1 29.179 Mh/s, GPU2 24.029 Mh/s, GPU3 28.896 Mh/s
            if (substr($line, 0, 8) == 'ETH: GPU') {

                $clean_line = substr($line, 5);

                $arr_gpu_speed = explode(', ', $clean_line);

                $gpu_number = 0;
                $total_shares = 0;
                foreach ($arr_gpu_speed as $k_gpu => $gpu_info) {
                    $arr_gpu = explode(' ', $gpu_info);

                    if ($arr_gpu[1] == 'off') {
                        $arr_gpu[1] = 0;
                    }

                    $arr_gpu_info[$gpu_number]['eth_speed'] = $arr_gpu[1];

                    $gpu_number++;
                }

            }

            // Get totals - ETH - Total Speed: 110.884 Mh/s, Total Shares: 271(78+84+57+57), Rejected: 0, Time: 02:47
            if (substr($line, 0, strlen('ETH - Total Speed')) == 'ETH - Total Speed') {

                $clean_line = substr($line, 5);

                $arr_totals = explode(' ', $clean_line);
                $shares = str_replace(array('),', '('), array('', '+'), $arr_totals[7]);
                $arr_shares = explode('+', $shares);

                $arr_totals_info = array(
                    'eth_total_speed' => $arr_totals[3],
                    'eth_total_shares' => $arr_shares[0],
                    'eth_total_rejected' => intval($arr_totals[9]),
                );


                // Shares for individual cards
                $arr_card_shares = array_slice($arr_shares, 1, count($arr_shares));

                if (!empty($arr_card_shares)) {
                    foreach ($arr_card_shares as $k_gpu => $gpu_shares) {
                        $arr_gpu_info[$k_gpu]['eth_shares'] = $gpu_shares;
                    }
                }

                // Let's calculate some averages for the totals array
                if (!empty($arr_gpu_info)) {
                    $total_cards = count($arr_gpu_info);

                    $arr_totals_info['eth_avg_speed_per_card']  = round($arr_totals_info['eth_total_speed'] / $total_cards, 2);
                    $arr_totals_info['eth_avg_shares_per_card'] = round($arr_totals_info['eth_total_shares'] / $total_cards, 2);

                    $temp_total = $fan_total = 0;
                    foreach ($arr_gpu_info as $k_gpu => $gpu) {
                        $temp_total += $gpu['temp'];
                        $fan_total  += $gpu['fan'];
                        $arr_gpu_info[$k_gpu]['eth_shares_pct'] = round(100 * ($gpu['eth_shares'] / $arr_totals_info['eth_total_shares']), 2);
                    }

                    $arr_totals_info['avg_temp'] = round($temp_total / $total_cards, 2);
                    $arr_totals_info['avg_fan']  = round($fan_total / $total_cards, 2);

                }

            }

            $i++;

        }

        $objDateTime = new DateTime('NOW');
        $time = str_replace('+', 'Z', $objDateTime->format(DateTime::RFC3339));

        $log_entry_total = $time.', machine: '.MACHINE_NAME;

        foreach ($arr_totals_info as $label => $v) {
            $log_entry_total .= ', '.$label.': '.$v;
        }

        file_put_contents(FILE_LOG_TOTALS, $log_entry_total."\n", FILE_APPEND);

        $log_entry_gpu = '';
        foreach ($arr_gpu_info as $gpu_k => $gpu) {

            $log_entry_gpu .= $time.', machine: '.MACHINE_NAME;
            foreach ($gpu as $label => $v) {
                $log_entry_gpu .= ', '.$label.': '.$v;
            }

            $log_entry_gpu .= "\n";

        }

        file_put_contents(FILE_LOG_GPUS, $log_entry_gpu, FILE_APPEND);

        if (DEBUG_MODE === true) {
            print "===========================================================================\n\n";
            print $log_entry_total . "\n";
            print $log_entry_gpu . "\n";
            print "===========================================================================\n\n";
            print_r($arr_totals_info) . "\n";
            print "===========================================================================\n\n";
            print_r($arr_gpu_info) . "\n";
            print "===========================================================================\n\n";
            print_r($arr_lines) . "\n";
        }

    } else if (DEBUG_MODE === true) {

        print 'Could not reach Claymore API on '.SERVICE_URL.' port '.SERVICE_PORT;

    }

    if (RUN_INDEFINITELY === false) {
        exit;
    }

    sleep(UPDATE_FREQ);

}





/**
 * This function fetches data via curl
 * @param $url
 * @return array
 */
function fetch_via_curl($url) {

    $arr_curl_result = array();

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);

    $arr_curl_result['curl_exec'] = curl_exec($ch);
    $arr_curl_result['curl_getinfo'] = curl_getinfo($ch);

    if (array_key_exists('http_code', $arr_curl_result['curl_getinfo']) === true) {

        if ($arr_curl_result['curl_getinfo']['http_code'] == '200') {
            $quick_status = 'ok';
        } else {
            $quick_status = 'error';
        }

        $http_code = $arr_curl_result['curl_getinfo']['http_code'];

    } else {

        $quick_status = 'error';
        $http_code = 'None';

    }

    $arr_curl_result['quick_status'] = $quick_status;
    $arr_curl_result['http_code']    = $http_code;


    curl_close($ch);

    return $arr_curl_result;

}


?>