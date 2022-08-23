<?php

namespace App\Http\Controllers;

use App\Jobs\Shopify\ConfigureWebhooks;
use App\Mail\InstallComplete;
use App\Models\Store;
use App\Models\User;
use App\Traits\FunctionTrait;
use App\Traits\RequestTrait;
use Exception;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;

class InstallationController extends Controller {
    use FunctionTrait, RequestTrait;

    /**
     * Three scenarios can happen
     * New installation
     * Re-installation
     * Opening the app
     */
    
    public function startInstallation(Request $request) {
        try {
            $validRequest = $this->validateRequestFromShopify($request->all());
            if($validRequest) { 
                $shop = $request->has('shop'); //Check if shop parameter exists on the request.
                if($shop) {
                    $storeDetails = $this->getStoreByDomain($request->shop);
                    $endpoint = 'https://'.$request->shop.
                                '/admin/oauth/authorize?client_id='.config('custom.shopify_api_key').
                                '&scope='.config('custom.api_scopes').
                                '&redirect_uri='.config('app.url').'shopify/auth/redirect';
                        
                    return $storeDetails !== null && $storeDetails !== false ?
                        $this->checkIfTokenIsValidAndResumeInstallation($storeDetails, $request) :
                        Redirect::to($endpoint);
                    
                } else throw new Exception('Shop parameter not present in the request');
            } else throw new Exception('Request is not valid!');
        } catch(Exception $e) {
            Log::info($e->getMessage().' '.$e->getLine());
            print_r($e->getMessage().' '.$e->getLine());
        }
    }

    private function checkIfTokenIsValidAndResumeInstallation($store, $request) {
        //store record exists and now determine whether the access token is valid or not
        //if not then forward them to the re-installation flow
        //if yes then redirect them to the login page.
                        
        return $this->checkIfAccessTokenIsValid($store) ? 
            Redirect::route('login') : 
            Redirect::to('https://'.$request->shop.
                '/admin/oauth/authorize?client_id='.config('custom.shopify_api_key').
                '&scope='.config('custom.api_scopes').
                '&redirect_uri='.config('app.url').'shopify/auth/redirect'
            );
    }

    public function handleRedirect(Request $request) {
        try {
            $validRequest = $this->validateRequestFromShopify($request->all());
            if($validRequest) {
                if($request->has('shop') && $request->has('code')) {
                    $shop = $request->shop;
                    $code = $request->code;
                    $accessToken = $this->requestAccessTokenFromShopifyForThisStore($shop, $code);
                    if($accessToken !== false && $accessToken !== null) {
                        $shopDetails = $this->getShopDetailsFromShopify($shop, $accessToken);
                        $saveDetails = $this->saveStoreDetailsToDatabase($shopDetails, $accessToken);
                        if($saveDetails) {  
                            //At this point the installation process is complete.
                            return Redirect::route('login');
                        } else {
                            Log::info('Problem during saving shop details into the db');
                            Log::info($saveDetails);
                            return response()->json(['status' => false,'message' => 'Problem during installation. please check logs.']);
                        }
                    } else throw new Exception('Invalid Access Token '.$accessToken);
                } else throw new Exception('Code / Shop param not present in the URL');
            } else throw new Exception('Request is not valid!');
        } catch(Exception $e) {
            Log::info($e->getMessage().' '.$e->getLine());
            return response()->json(['status' => false, 'message' => $e->getMessage().' '.$e->getLine()]);
        }
    }

    public function saveStoreDetailsToDatabase($shopDetails, $accessToken) {
        try {
            $payload = [
                'access_token' => $accessToken,
                'myshopify_domain' => $shopDetails['myshopify_domain'],
                'id' => $shopDetails['id'],
                'email' => $shopDetails['email'],
                'name' => $shopDetails['name'],
                'phone' => $shopDetails['phone'],
                'address1' => $shopDetails['address1'],
                'address2' => $shopDetails['address2'],
                'zip' => $shopDetails['zip']
            ];
            $store_db = Store::updateOrCreate(['myshopify_domain' => $shopDetails['myshopify_domain']], $payload); 
            $random_password = Str::random(10);
            Log::info('Password generated '.$random_password); 
            $user_payload = [
                'email' => $shopDetails['email'],
                'password' => bcrypt($random_password),
                'store_id' => $store_db->table_id,
                'name' => $shopDetails['name']
                //'email_verified_at' => date('Y-m-d h:i:s')
            ];
            $this->createAUserLoginForTheStore($store_db, $user_payload);
            ConfigureWebhooks::dispatch($store_db->table_id);
            Session::flash('success', 'Installation for your store '.$shopDetails['name'].' has completed and the credentials have been sent to '.$shopDetails['email'].'. Please login.');
            //Create ur own mail handler here
            //Send the credentials to the registered email address on Shopify.
            //Mail::to($shopDetails['email'])->send(new InstallComplete($user_payload, $random_password));
            return true;
        } catch(Exception $e) {
            Log::info($e->getMessage().' '.$e->getLine());
            return false;
        }
    }

    private function getShopDetailsFromShopify($shop, $accessToken) {
        try {
            $endpoint = getShopifyURLForStore('shop.json', ['myshopify_domain' => $shop]);
            $headers = getShopifyHeadersForStore(['access_token' => $accessToken]);
            $response = $this->makeAnAPICallToShopify('GET', $endpoint, null, $headers);
            if($response['statusCode'] == 200) {
                $body = $response['body'];
                if(!is_array($body)) 
                    $body = json_decode($body, true);
                return $body['shop'] ?? null;
            } else {
                Log::info('Response recieved for shop details');
                Log::info($response);
                return null;
            }
        } catch(Exception $e) {
            Log::info('Problem getting the shop details from shopify');
            Log::info($e->getMessage().' '.$e->getLine());
            return null;
        }
    }

    private function requestAccessTokenFromShopifyForThisStore($shop, $code) {
        try {
            $endpoint = 'https://'.$shop.'/admin/oauth/access_token';
            $headers = ['Content-Type: application/json'];
            $requestBody = json_encode([
                'client_id' => config('custom.shopify_api_key'),
                'client_secret' => config('custom.shopify_api_secret'),
                'code' => $code
            ]);
            $response = $this->makeAPOSTCallToShopify($requestBody, $endpoint, $headers);
            if($response['statusCode'] == 200) {
                $body = $response['body'];
                if(!is_array($body)) $body = json_decode($body, true);
                if(is_array($body) && isset($body['access_token']) && $body['access_token'] !== null)
                    return $body['access_token'];
            }
            return false;
        } catch(Exception $e) {
            return false;
        }
    }

    /**
       * Write some code here that will use the Guzzle library to fetch the shop object from Shopify API
       * If it succeeds with 200 status then that means its valid and we can return true;        
     */

    private function checkIfAccessTokenIsValid($storeDetails) {
        try {
            if($storeDetails !== null && isset($storeDetails->access_token) && strlen($storeDetails->access_token) > 0) {
                $token = $storeDetails->access_token;
                $endpoint = getShopifyURLForStore('shop.json', $storeDetails);
                $headers = getShopifyHeadersForStore($storeDetails);
                $response = $this->makeAnAPICallToShopify('GET', $endpoint, null, $headers, null);
                return $response['statusCode'] === 200;
            }
            return false;
        } catch(Exception $e) {
            return false;
        }
    }
}
