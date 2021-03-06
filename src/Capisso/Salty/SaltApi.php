<?php namespace Capisso\Salty;

use Requests;

class SaltApi {
    private $host;
    private $port;
    private $credentialType;
    private $credentials;
    private $validation;

    private $token;

    public function __construct($host, $port, $credentialType, $credentials, $validation) {
        $this->host = $host;
        $this->port = $port;
        $this->credentialType = $credentialType;
        $this->credentials = $credentials;
        $this->validation = $validation;
    }

    /**
     * Get the authentication token from cache, or refresh if required.
     *
     * @return string authentication token
     **/
    protected function getToken() {
        if ($this->token && microtime(true) <= $this->token->expire) {
            return $this->token->token;
        }

        $data = array_merge($this->credentials, array(
            'eauth' => $this->credentialType
        ));
        $resp = $this->request(Requests::POST, '/login', $data);
        $this->token = $resp->return[0];
        return $this->token->token;
    }

    /**
     * Make a plain request to the Salt API given a method, page and any data.
     *
     * @return stdClass decoded JSON output
     **/
    protected function request($method, $page, $data=array(), $jsonData=false, $headers=array()) {
        $headers = array_merge($headers, array(
            "Accept" => "application/json"
        ));
        $options = array(
            'useragent' => 'Salty/php-requests',
            'verify' => $this->validation
        );
        $url = 'https://' . $this->host . ':' . $this->port . $page;

        if (!$jsonData) {
            $data = http_build_query($data, null, '&', PHP_QUERY_RFC1738);
        } else {
            $headers['Content-Type'] = 'application/json';
            $data = json_encode($data);
        }

        $resp = Requests::request($url, $headers, $data, $method, $options);
        if (!$resp->success) {
            throw new \Exception("Got status code " . $resp->status_code);
        }

        return json_decode($resp->body);
    }

    /**
     * Make an authenticated request to the Salt API.
     * Refreshes auth token if required.
     *
     * @return stdClass decoded JSON output
     **/
    protected function authenticatedRequest($method, $page, $data=array(), $jsonData=false, $headers=array()) {
        $token = $this->getToken();

        $headers = array_merge($headers, array(
            "X-Auth-Token" => $token
        ));

        return $this->request($method, $page, $data, $jsonData, $headers);
    }

    /**
     * Make a call with some client to salt.
     *
     * @return stdClass
     **/
    protected function callWithClient($client, $targetType, $target, $call, $params) {
        $obj = array(array(
            'client' => $client,
            'tgt' => $target,
            'expr_form' => $targetType,
            'fun' => $call,
            'arg' => $params
        ));

        $resp = $this->authenticatedRequest(Requests::POST, '/', $obj, true);
        return $resp;
    }

    /**
     * Make a synchronous call to salt.
     *
     * @return stdClass
     **/
    public function call($targetType, $target, $call, $params=array()) {
        return $this->callWithClient('local', $targetType, $target, $call, $params)->return[0];
    }

    /**
     * Make an asynchronous call to salt.
     *
     * @return stdClass contains jid (string) and minions (array of strings)
     **/
    public function callAsync($targetType, $target, $call, $params=array()) {
        return $this->callWithClient('local_async', $targetType, $target, $call, $params)->return[0];
    }

    /**
     * Make a synchronous "wheel" call to salt. This manages the salt master.
     *
     * @return stdClass
     **/
    public function callWheel($call, $params=array()) {
        $obj = array(array_merge($params, array(
            'client' => 'wheel',
            'fun' => $call
        )));

        $resp = $this->authenticatedRequest(Requests::POST, '/', $obj, true);
        return $resp->return[0];
    }

    /**
     * Make a synchronous "runner" call to salt. This manages the salt master.
     *
     * @return stdClass
     **/
    public function callRunner($call, $params=array()) {
        $obj = array(array_merge($params, array(
            'client' => 'runner',
            'fun' => $call
        )));

        $resp = $this->authenticatedRequest(Requests::POST, '/', $obj, true);
        return $resp->return[0];
    }

    /**
     * Get the result of a previous asynchronous call.
     * Note that this will still return even if the job is incomplete!
     *
     * @return stdClass
     **/
    public function getJobResult($jobId) {
        $resp = $this->authenticatedRequest(Requests::GET, '/jobs/' . $jobId);
        return $resp->return[0];
    }
}
