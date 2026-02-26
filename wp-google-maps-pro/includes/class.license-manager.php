<?php

namespace WPGMZA;

class LicenseManager {
    private $cachedLicenses;

    public function __construct(){
        $this->cachedLicenses = array();

        $this->hook();
    }

    public function hook(){

        add_filter('wpgmza_global_settings_tabs', array($this, 'getLicenseManagerTab'), 30);
		add_filter('wpgmza_global_settings_tab_content', array($this, 'getLicenseManagerTabContent'), 30);

        add_action('wpgmza_global_settings_page_created', array($this, 'cacheStoredLicenses'), 30, 1);
        add_action('wpgmza_global_settings_before_redirect', array($this, 'linkLicenses'), 30, 1);

        add_action('admin_init', array($this, 'scheduleCheck'));

        add_filter('wpgmza_plugin_api_packet', array($this, 'pluginApiPacket'), 10, 2);

        add_filter('wpgmza_global_settings_json_serialize_filter', array($this, 'globalSettingsJsonSerialize'), 30, 1);

        $addOns = $this->getAddOns();
        foreach($addOns as $slug => $config){
            add_action( "after_plugin_row_" . plugin_basename($config->baseFile), array($this, 'licensePluginRow'), 10, 3);
        }
    }

    public function pluginApiPacket($data, $slug = false){
        if(!empty($slug) && !empty($data) && is_array($data)){
            $license = $this->getLicenseKey($slug);
            if(!empty($license)){
                if(!empty($data['body'])){
                    $data['body']['licenseKey'] = $license;
                    $data['body']['licenseProduct'] = $slug;
                    $data['body']['licenseDomain'] = get_site_url();
                }
            }
        }

        return $data;
    }

    public function licensePluginRow($file, $data, $status){
        $slug = !empty($data['TextDomain']) ? str_replace("wp-google-maps-", "", $data['TextDomain']) : false;
        if(!empty($slug)){
            $notice = "";
            if(empty($this->getLicenseKey($slug))){
                $notice = sprintf(
                        '<strong>%s</strong> - %s <a href="%s">%s</a>',
                            $data['Name'],
                            __( 'Automatic updates disabled, please enter a license key to enable updates.', 'wp-google-maps'), 
                            esc_url(admin_url('admin.php?page=wp-google-maps-menu-settings#licensing')),
                            __("Update license key", 'wp-google-maps')
                        );
            } else {
                $statuses = $this->getLicenseStatus();
                if(!empty($statuses) && !empty($statuses->{$slug})){
                    $status = $statuses->{$slug};
                    if(!empty($status->error)){
                        $errorCode = $status->error;
                        $errorText = __("There was an issue verifying your license key, please contact support.", "wp-google-maps");
                        $errorLink = esc_url(admin_url('admin.php?page=wp-google-maps-menu-support'));
                        $errorAction = __("Get support", "wp-google-maps");
                        switch($errorCode){
                            case 'max_allowed_domains':
                                $errorText = __("Automatic updates disabled, you have exceeded your domain limit.", "wp-google-maps");
                                $errorLink = esc_url(admin_url('admin.php?page=wp-google-maps-menu-settings#licensing')); 
                                $errorAction = __("Update license key", "wp-google-maps");
                                break;
                            case 'key_expired':
                                $errorText = __("Automatic updates disabled, your license key has expired.", "wp-google-maps");
                                $errorLink = esc_url(admin_url('admin.php?page=wp-google-maps-menu-settings#licensing')); 
                                $errorAction = __("Update license key", "wp-google-maps");
                                break;
                            case 'no_key_found':
                                $errorText = __("Automatic updates disabled, your license key is invalid.", "wp-google-maps");
                                $errorLink = esc_url(admin_url('admin.php?page=wp-google-maps-menu-settings#licensing')); 
                                $errorAction = __("Update license key", "wp-google-maps");
                                break;
                        }

                        $notice = sprintf(
                            '<strong>%s</strong> - %s <a href="%s">%s</a>',
                                $data['Name'],
                                $errorText, 
                                $errorLink,
                                $errorAction
                            );
                    }

                    
                }
            }

            if(!empty($notice)){

                $notice = "<div class='update-message notice inline notice-warning notice-alt' style='margin: 5px'><p>{$notice}</p></div>";
                $html = array();
                $html[] = "<tr class='active'>";
                $html[] = "<td colspan='4'>{$notice}</td>";
                $html[] = "</tr>";

                echo implode("", $html);
            }
            
        }
        
    }

    public function linkLicenses($wpgmza = false){
        $addOns = $this->getAddOns();

        $statuses = (object) array();
        $storedStatuses = $this->getLicenseStatus();
        $domain = get_site_url();
        if(!empty($domain)){
            foreach($addOns as $slug => $config){
                $licenseKey = $this->getLicenseKey($slug);
                if(!empty($licenseKey)){
                    if(empty($this->cachedLicenses[$slug]) || $this->cachedLicenses[$slug] !== $licenseKey){
                        /* Key has changed or been added for the first time - Link now */
                        $linked = $this->request('link-domain', array('license_key' => $licenseKey, 'domain' => $domain));
                        $statuses->{$slug} = $linked;
                    } else {
                        if(!empty($storedStatuses)&& !empty($storedStatuses->{$slug})){
                            $statuses->{$slug} = $storedStatuses->{$slug};
                        }
                    }
                } 
            }
        }

        $this->setLicenseStatus($statuses);
    }

    public function cacheStoredLicenses($document = false){
        $addOns = $this->getAddOns();
        foreach($addOns as $slug => $config){
            $licenseKey = $this->getLicenseKey($slug);
            if(!empty($licenseKey)){
                $this->cachedLicenses[$slug] = $licenseKey;
            }
        }
    }

    public function getLicenseManagerTab($tabs){
        global $wpgmza;
        $engine = !empty($wpgmza) && !empty($wpgmza->settings) && !empty($wpgmza->settings->internal_engine) ? $wpgmza->settings->internal_engine : false;

        $style = "";
        if($engine !== 'atlas-novus'){
		    $style = "style='margin-right: 3px;'";
        }

        $status = "";
        $addOns = $this->getAddOns();
        foreach($addOns as $slug => $config){
            if(empty($status)){
                if(empty($this->getLicenseKey($slug))){
                    /* User is missing at least one license */
                    $statusStyles = "background: #c10000;color: #fff;width: 15px;height: 15px;display: inline-block;text-align: center;font-size: 10px;line-height: 14px;vertical-align: middle;border-radius: 16px;font-weight: 700;margin-left: 5px;";
                    $status = "<span style='{$statusStyles}' title='" . __("Missing license key!", "wp-google-maps") . "'>!</span>";
                }
            }
        }

		return $tabs . "<li {$style}><a href=\"#licensing\">" . __("License","wp-google-maps") . "{$status}</a></li>";
    }

    public function getLicenseManagerTabContent($content){
        global $wpgmza;

		$document = new DOMDocument();
        $document->loadPHPFile($wpgmza->internalEngine->getTemplate('license-manager-panel.html.php', WPGMZA_PRO_DIR_PATH));

        $template = $document->querySelector('[data-license-field-template]');
        /* Now add the various add-on fields */
        $licenseFields = $this->getAddOns();
        $statuses = $this->getLicenseStatus();

        foreach($licenseFields as $slug => $config){
            $fieldRow = $template->cloneNode(true);

            if($titleElement =  $fieldRow->querySelector('.title')){
                $titleElement->import(sprintf( __( "%s License Key", "wp-google-maps" ), $config->title ) );
            }

            if($input = $fieldRow->querySelector('input')){
                $input->setAttribute('name', "license_key_{$slug}");
                $input->setAttribute('id', "license_key_{$slug}");
                $input->setAttribute('placeholder', sprintf( __( "Paste your %s license key...", "wp-google-maps" ), $config->title ) );
            }
            
            if($statusElement = $fieldRow->querySelector('[data-license-field-status]')){
                if(empty($this->getLicenseKey($slug))){
                    $statusElement->import(__("Key required for updates", "wp-google-maps"));
                } else {
                    if(!empty($statuses) && !empty($statuses->{$slug})){
                        $status = $statuses->{$slug};
                        if(!empty($status->success)){
                            /* Key is considered valid and linked */
                            $statusElement->import(__("License valid", "wp-google-maps"));
                            $statusElement->setAttribute('style', "color:#4e8d17");
                        } else if(!empty($status->error)){
                            $errorCode = $status->error;
                            $errorLabel = __("License error, please contact support", "wp-google-maps");
                            switch($errorCode){
                                case 'missing_parameters':
                                    $errorLabel = __("Request denied", "wp-google-maps");
                                    break;
                                case 'max_allowed_domains':
                                    $errorLabel = __("Domain limit exceeded", "wp-google-maps");
                                    break;
                                case 'key_expired':
                                    $errorLabel = __("License has expired", "wp-google-maps");
                                    break;
                                case 'no_key_found':
                                    $errorLabel = __("License key is invalid", "wp-google-maps");
                                    break;
                            }

                            $statusElement->import($errorLabel);
                            $statusElement->setAttribute('style', "color:#c10000");
                        }
                    }
                }
            }

            

            $document->querySelector('[data-license-field-wrapper]')->import($fieldRow);
        }

        $template->remove();

		return $content . "<div id='licensing'>" . $document->html . "</div>";
    }

    public function getAddOns(){
        $default = array(
            'pro' => (object) array(
                "title" => __("Pro Add-on", 'wp-google-maps'),
                'baseFile' => WPGMZA_PRO_FILE
            ) 
        );

        return apply_filters('wpgmza_license_add_ons', $default);
    }

    public function getLicenseKey($addon){
        global $wpgmza; 

        $licenseKeyField = "license_key_{$addon}";
        if(!empty($wpgmza) && !empty($wpgmza->settings)){
            if(!empty($wpgmza->settings->{$licenseKeyField})){
                return $wpgmza->settings->{$licenseKeyField};
            }
        }
        return false;
    }

    public function scheduleCheck(){
        $today = date('Y-m-d');
        $scheduled = get_option('wpgmza_license_schedule');
        if(!empty($scheduled)){
            if(strtotime($today) >= strtotime($scheduled)){
                /* It's time we run a recheck of the license keys stored */
                $addOns = $this->getAddOns();

                $statuses = (object) array();
                $domain = get_site_url();
                if(!empty($domain)){
                    foreach($addOns as $slug => $config){
                        $licenseKey = $this->getLicenseKey($slug);
                        if(!empty($licenseKey)){
                            /* We have a key, we should now perform a re-link to make sure the license is still valid */
                            /* This is purely to alert the owner of the site to any issues, it's still rechecked server side on update */
                            $linked = $this->request('link-domain', array('license_key' => $licenseKey, 'domain' => $domain));
                            $statuses->{$slug} = $linked;
                        } 
                    }
                }

                $this->setLicenseStatus($statuses);
            }
        }
    }

    public function globalSettingsJsonSerialize($data){
        if(!empty($data) && is_object($data)){
            try{
                foreach($data as $key => $value){
                    if(strpos($key, "license_key") !== FALSE){
                        unset($data->{$key});
                    }

                    if(strpos($key, 'licenseKey') !== FALSE){
                        unset($data->{$key});
                    }
                }
            } catch (\Exception $ex){

            } catch (\Error $err){

            }
        }
        
        return $data;
    }

    private function getLicenseStatus(){
        $status = get_option('wpgmza_license_status');
        if(!empty($status)){
            try{
                $status = json_decode($status);
                return $status;
            } catch (\Exception $ex){

            } catch (\Error $err){

            }
        }
        return false;
    }

    private function setLicenseStatus($data){
        if(!empty($data) && is_object($data)){
            update_option('wpgmza_license_status', json_encode($data));

            /* Schedule the next recheck timer - Where we'll revalidate the license for the end user */
            update_option('wpgmza_license_schedule', date('Y-m-d', strtotime("+20 day")));
        } else {
            delete_option('wpgmza_license_status');
        }
    }

    private function request($route, $data = false){
        $url = "https://www.wpgmaps.com/?rest_route=/wp-go-maps-account/v1/{$route}";

        $data = !empty($data) && is_array($data) ? $data : false;
        $response = wp_remote_post($url, array('method' => 'POST', 'body' => $data));
        
        if(!is_wp_error($response)){
            if(!empty($response)){
                try{
                    $body = wp_remote_retrieve_body($response);
                    $json = json_decode($body);
                    return $json;
                } catch(\Exception $ex){

                } catch(\Error $err){

                }
            }
        }
        return (object) array("success" => false, "message" => __("We could not communicate with our licensing server", "wp-google-maps"));
    }
}