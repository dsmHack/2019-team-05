<?php
/**
 *  Plugin Name: Travis Test
 *  Author: Travis Sanderson (travis.sanderson@workiva.com)
 *
 */

class TravisTest_Plugin {
    public function __construct() {
        add_action('admin_menu', array($this, 'handle_admin_menu'));
        add_action('plugins_loaded', array($this, 'plugins_loaded')); 
        add_action('user_register', array($this, 'update_user_hours'));
        add_action('wp_login', array($this, 'update_user_hours'));
        add_action('wp_loaded', array($this, 'update_user_hours'));
    }

    // Adds this plugin to the Tools WordPress section.
    public function handle_admin_menu() {
        add_management_page(
            // Page title
            'Travis Test',
            // Menu title
            'Travis Test',
            // Capability requirements
            'import',
            // Menu slug (?page=travis-test-import)
            'travis-test-import',
            // On success callback
            array($this, 'display_management_page')
        );
    }

    public function plugins_loaded() {
        // runs on every page (admin and front-end) after plugins have loaded
    }

    // Adds the HTML for changing the Salesforce credentials.
    public function display_management_page() {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_SESSION['access_token'])) {
            update_option("ttp_access_token", $_SESSION['access_token']);
        }
        if (isset($_SESSION['refresh_token'])) {
            update_option("ttp_refresh_token", $_SESSION['refresh_token']);
        }
        if (isset($_SESSION['instance_url'])) {
            update_option("ttp_instance_url", $_SESSION['instance_url']);
        }

        $access_token = get_option("ttp_access_token");
        $refresh_token = get_option("ttp_refresh_token");
        $instance_url = get_option("ttp_instance_url");

        echo <<<EOF
        <h1>SalesForce + myCRED integration details</h1>
        <table>
            <tr>
                <td>Access token</td><td>$access_token</td>
            </tr>
            <tr>
                <td>Refresh token</td><td>$refresh_token</td>
            </tr>
            <tr>
                <td>Instance URL</td><td>$instance_url</td>
            </tr>
        </table>

        <p>Click <a href='/wp-content/plugins/travis-test-plugin/oauth.php' target='_blank'>here</a> to re-authenticate with SalesForce.</p>
EOF;
    }

    // Updates the user's hours by hitting a Salesforce api (see fetch_hours).
    // Those hours are written to the user's myCRED row.
    public function update_user_hours() {
        $user = wp_get_current_user();
        if (is_null($user) or empty($user->id)) {
            // If the user object doesn't have an id, we can't proceed.
            return;
        }

        $previous_date = $this->fetch_previous_date($user->id);
        $current_date = $this->today();

        if ($previous_date == $current_date) {
            // Do not do anything if the user is already up to date.
            return;
        }

        $hours = $this->fetch_hours($user->user_email, $previous_date, $current_date);
        $this->write_mycred($user->id, $hours);
        update_user_meta($user->id, 'last_fetch_date', $current_date);
    }

    // Fetches the last known start date for the user.
    public function fetch_previous_date($user_id) {
        $previous_date = get_user_meta($user_id, 'last_fetch_date', true);
        if (empty($previous_date) ) {
            // See the function [today] for the format used.
            $previous_date = '2019-01-01';
        }
        return $previous_date;
    }

    // Returns the hours the user has worked between the two dates.
    public function fetch_hours($email, $start_date, $end_date) {
        $access_token = get_option("ttp_access_token");
        $query = "SELECT+SUM(GW_Volunteers__Total_Hours_Worked__c)+FROM+GW_Volunteers__Volunteer_Hours__c+WHERE+CreatedDate+>+" . $start_date . "T00:00:00.000Z+AND+CreatedDate+<+" .$end_date . "T00:00:00.000Z+AND+GW_Volunteers__Contact__r.Email+=+'" . $email . "'+AND+GW_Volunteers__Total_Hours_Worked__c+>+0+GROUP+BY+GW_Volunteers__Contact__r.Email";
        $instance_url = get_option("ttp_instance_url");
        $url = "$instance_url/services/data/v20.0/query?q=" . urlencode($query);
    
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER,
                array("Authorization: OAuth $access_token"));
    
        $json_response = curl_exec($curl);
        curl_close($curl);
        
        // TODO: Pull the actual info out of the query instead of returning 1
        return 1;
    }

    // Adds the given hours to the given user id in the myCRED table.
    public function write_mycred($user_id, $hours) {
        $mycred = mycred('points');
        $mycred->add_creds(
            'add_hours',
            $user_id,
            12.5 * $hours,
            'add volunteer hours'
        );
    }

    // Returns today's date. It's important to use this when referencing
    // today's date as this format is what is persisted in the user meta.
    public function today() {
        return date('Y-m-d');
    }
}

$TravisTestPlugin = new TravisTest_Plugin();
