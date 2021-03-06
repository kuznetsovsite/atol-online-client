<?php

namespace AtolOnlineClient;

use AtolOnlineClient\Configuration\Connection;
use Doctrine\Common\Cache\Cache;

use Guzzle\Http\Client;

use Guzzle\Http\Exception\BadResponseException;
use Guzzle\Http\Message\Response;
use Psr\Log\LoggerInterface;

class AtolOnlineApi
{

    const API_VERSION_V4 = 'v4';
    const API_VERSION_V3 = 'v3';

    const TOKEN_CACHE_KEY = 'crm_fiscal_atol_online_token';
    const TOKEN_CACHE_TIME = 60 * 60 * 24;

    private $baseApiUrl = 'https://online.atol.ru/possystem';

    private $login;
    private $pass;
    private $groupCode;
    private $debug;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Cache
     */
    private $cache;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var int
     */
    private $attempts;

    /**
     * @var int
     */
    private $attemptsCheckStatus;

    /** @var string  */
    private $version;

    /**
     * @param Client $client
     * @param Connection $connectionConfig
     */
    public function __construct(Client $client, Connection $connectionConfig)
    {
        $this->client = $client;
        $this->login = $connectionConfig->login;
        $this->pass = $connectionConfig->pass;
        $this->groupCode = $connectionConfig->group;
        if (!$connectionConfig->version) {
            $connectionConfig->version = self::API_VERSION_V3;
        }
        $this->version = $connectionConfig->version;
        $this->debug = $connectionConfig->isDebug();
        $this->attempts = 0;
        if ($connectionConfig->isTestMode()) {
            $this->baseApiUrl = 'https://testonline.atol.ru/possystem';
        }
    }

    /**
     * Приход
     *
     * @param $paymentReceiptRequest
     * @return string
     */
    public function sell($paymentReceiptRequest)
    {
        if ($response = $this->sendOperationRequest('sell', $paymentReceiptRequest)) {
            return $response->getBody()->__toString();
        };
    }

    /**
     * Возврат прихода
     *
     * @param $paymentReceiptRequest
     * @return string
     */
    public function sellRefund($paymentReceiptRequest)
    {
        if ($response = $this->sendOperationRequest('sell_refund', $paymentReceiptRequest)) {
            return $response->getBody()->__toString();
        };
    }

    /**
     * Запрос для проверки статуса
     *
     * @param $uuid
     * @return mixed
     */
    public function checkStatus($uuid)
    {
        $token = $this->getToken();
        $url = $this->buildUrl('report/'.$uuid, $token);
        $request = $this->client->get($url);

        $response = false;
        try {
            $this->attemptsCheckStatus++;
            $response = $request->send();
        } catch (BadResponseException $e) {
            $this->cache->delete($this->getTokenCacheKey());
            $body = json_decode($e->getResponse()->getBody());
            if ($this->isTokenExpired($body) && $this->attemptsCheckStatus <= 1) {
                return $this->checkStatus($uuid);
            }
            $this->logDebug($url, $uuid, $e->getResponse());
        }

        if ($response) {
            return $response->getBody()->__toString();
        }

        return false;
    }

    /**
     * @param $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param Cache $cache
     */
    public function setCache(Cache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * @param mixed $version
     */
    public function setVersion($version): void
    {
        $this->version = $version;
    }

    /**
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * @return bool|mixed
     * @throws \Exception
     */
    protected function getToken()
    {
        $data = [
            'login' => $this->login,
            'pass' => $this->pass,
        ];

        if ($token = $this->cache->fetch($this->getTokenCacheKey())) {
            return $token;
        }

        $dataJson = json_encode((object)$data, JSON_UNESCAPED_UNICODE);
        $url = $this->baseApiUrl
            .'/'.$this->version
            .'/getToken';

        $request = $this->client->createRequest('POST', $url, null, $dataJson);
        $response = false;
        try {
            $response = $this->client->send($request);
        } catch (BadResponseException $e) {
            if ($this->logger) {
                $this->logger->error($e->getResponse()->getBody());
            }
        }

        if ($response) {
            $response = json_decode($response->getBody());

            if ($this->version === self::API_VERSION_V4) {
                if (!isset ($response->error)) {
                    $this->cache->save($this->getTokenCacheKey(), $response->token, self::TOKEN_CACHE_TIME);

                    return $response->token;
                } else {
                    $this->logger->error($response->error->code . ' '. $response->error->text);
                }
            } else {
                if (isset($response->code) && ($response->code == 1 || $response->code == 0)) {
                    $this->cache->save($this->getTokenCacheKey(), $response->token, self::TOKEN_CACHE_TIME);

                    return $response->token;
                }
            }

        }


        return false;
    }

    /**
     * @return string
     */
    protected function getTokenCacheKey()
    {
        return self::TOKEN_CACHE_KEY.'_'.md5($this->login.$this->pass).'_'.$this->version;
    }

    /**
     * @param string $operation
     * @param null $token
     * @return string
     */
    protected function buildUrl($operation, $token = null)
    {
        $url = $this->baseApiUrl
            .'/'.$this->version
            .'/'.$this->groupCode
            .'/'.$operation;

        if ($token) {
            if ($this->version === self::API_VERSION_V4) {
                $url .= '?token='.$token;
            } elseif ($this->version === self::API_VERSION_V3) {
                $url .= '?tokenid='.$token;
            }
        }

        return $url;
    }

    /**
     * @param string $operation
     * @param string $data
     * @return Response|bool
     */
    protected function sendOperationRequest($operation, $data)
    {
        $token = $this->getToken();
        $url = $this->buildUrl($operation, $token);

        $request = $this->client->createRequest('POST', $url, null, $data);
        $response = false;
        try {
            $this->attempts++;
            $response = $this->client->send($request);
        } catch (BadResponseException $e) {
            $this->cache->delete($this->getTokenCacheKey());
            $body = json_decode($e->getResponse()->getBody());
            if ($this->isTokenExpired($body) && $this->attempts <= 1) {
                return $this->sendOperationRequest($operation, $data);
            }
            $this->logDebug($url, $data, $e->getResponse());
        }
        if ($response) {
            $this->logDebug($url, $data, $response);
        }

        return $response;
    }

    protected function logDebug($url, $data, Response $response)
    {
        if ($this->debug && $this->logger) {
            $v = "* URL: ".$url;
            $v .= "\n * POSTFILEDS: ".$data;
            $v .= "\n * RESPONSE HEADERS: ".$response->getRawHeaders();
            $v .= "\n * RESPONSE BODY: ".$response->getBody();
            $v .= "\n * ATTEMPTS: ".$this->attempts;
            $this->logger->debug($v);
        }
    }

    protected function isTokenExpiredCode($code)
    {
        return in_array($code, [4, 5, 6, 12, 13, 14]);
    }

    private function isTokenExpired($body)
    {
        return isset($body->error) && $this->isTokenExpiredCode($body->error->code);
    }
}
