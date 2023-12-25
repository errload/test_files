<?php
namespace App\Components\Telephony;

use App\Exceptions\BadRequest;
use App\Helpers\System;
use App\Helpers\Http;
use App\Exceptions\HttpRequestError;

class YeastarcloudpbxTelephony extends Telephony {

    protected $client = [];
    protected $channels = null;
    protected $gmt = 0;
    protected $source_providers = null;
    protected $http = null;
    protected $api_host = null;
    protected $api_prefix = '/api/v2.0.0/';

    protected $login = null;
    protected $password = null;
    protected $access_token = null;
    protected $refresh_token = null;

    protected $fromDate = null;
    protected $toDate = null;

    public static function getClients()
    {
        $app = $GLOBALS['CRM_APP'];
        $pdo = $app->GetPDO();

        $data = [];

        $sql = "SELECT * FROM `clients_sources` WHERE `source_type` = 'telephony' AND `source_providers` = 'yeastarcloudpbx' AND `source_active` = 1";
        $res = $pdo->prepare($sql);
        $res->execute();

        while ($item = $res->fetch()) {
            $item['sources'] = isset($item['sources']) ? explode(',', $item['sources']) : null;
            $data[] = $item;
        }

        return $data;
    }

    public function getAuthTokens()
    {
        $this->login = $this->client['client_id'];
        $this->password = md5($this->client['token']);
        if (!isset($this->login) || !isset($this->password)) throw new BadRequest('Login and/or password is not set');

        $headers = ['Content-Type:application/json; charset=utf-8'];
        $request_data = json_encode([
            'username' => $this->login,
            'password' => $this->password,
            'port' => '8260',
            'version' => '2.0.0'
        ]);

        $response = $this->http->GetRequest($this->api_host . 'login', 'post', $request_data, $headers);
        $response = json_decode($response);
        if ($response && $response->status == 'Failed') throw new HttpRequestError("Can't refresh token, empty api server response");

        $this->access_token = $response->token;
        $this->refresh_token = $response->refreshtoken;
    }

    public function loadCalls($client)
    {
        if (empty($client)) return;

        $this->setClient($client);
        $this->point = (int) $this->client['point'];
        $this->api_host = $this->client['api_host'] . $this->api_prefix;
        $this->source_providers = $this->client['source_providers'];

        $this->channels = $this->client['source_channels'] ? trim($this->client['source_channels']) : 'all';
        $this->channels = str_replace(' ', '', $this->channels);
        $this->channels = str_replace(';', ',', $this->channels);

        $loadable = $this->initPeriod(false, 7, 30);
        if (!$loadable) return;

        $cabinetConfig = System::GetCabinetConfig($this->point);
        $this->gmt = (int) $cabinetConfig['ext_options']['gmt'];
        $this->http = Http::GetInstance();

        do {
            $calls = $this->getApiCalls();
            $this->usave(array_values($calls));
            $this->setLastUpdate();
        } while ($this->setNextDateInterval());
    }

    protected function getApiCalls()
    {
        $this->getAuthTokens();

        $starttime = date('Y-m-d H:m:s', $this->fromDate);
        $endtime = date('Y-m-d H:m:s', $this->toDate);

        // получение random для скачивания файла
        $request_data = json_encode(['number' => $this->channels, 'starttime' => $starttime, 'endtime' => $endtime]);
        $response = $this->http->GetRequest($this->api_host . "cdr/get_random?token={$this->access_token}", 'post', $request_data);

        $response = json_decode($response);
        if ($response && $response->status == 'Failed') return [];

        $starttime = str_replace(' ', '%20', $starttime);
        $endtime = str_replace(' ', '%20', $endtime);
        $random = $response->random;

        // url для загрузки файла
        $url = $this->api_host . "cdr/download?number={$this->channels}&starttime={$starttime}&endtime={$endtime}&token={$this->access_token}&random={$random}";

        // каталог для загрузки
        $path = CRM_PATH . '/var/tmp';
        if (!is_dir($path)) mkdir($path);
        $now = date('Y_m_d___H_i_s', strtotime('+2 hours'));
        $file_csv = $path . "/{$now}.csv";

        // запись в файл
        file_put_contents($file_csv, file_get_contents($url));
        if (!file_exists($file_csv)) return [];

        // чтение файла
        $calls = [];
        $call_info = [];

        $fh = fopen($file_csv, 'r');
        $col = fgetcsv($fh, 0, ',');
        while (($row = fgetcsv($fh, 0, ',')) !== false) $calls[] = array_combine($col, $row);
        unlink($file_csv);

        foreach ($calls as $item){
            $call_data = $this->parseCallItem($item);
            if (empty($call_data['call'])) continue;
            $call_uuid =  isset($call_data['uid']) ? $call_data['uid'] : false;
            if(empty($call_uuid)) continue;

            $call_info[$call_uuid] = $call_data['call'];
        }

        return $call_info;
    }

    protected function parseCallItem($item = [])
    {
        if (empty($item)) return [];

        $call = [
            'point' => $this->point,
            'provider_code' => $this->source_providers,
            'channel_id' => '',
            'date' => $item['timestart'],
            'call_uuid' => $item['callid'],
            'type' => $item['type'] == 'Inbound' ? 'in' : 'out',
            'from' => $item['callfrom'],
            'to' => $item['callto'],
            'via' => '',
            'wait_duration' => $item['callduraction'],
            'duration' => $item['talkduraction'],
            'state' => $item['status'] == 'ANSWERED' ? 'answered' : 'missed',
            'record_uuid' => $item['recording'],
            'auth_count' => 0,
            'last_auth_date' => '0000-00-00 00:00:00',
        ];

        $uid = md5($item['timestart'] . $item['callfrom']);
        return ['uid' => $uid, 'call' => $call];
    }

}
