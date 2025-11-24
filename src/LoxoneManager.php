<?php

namespace Goslovakia\Loxone;

use Goslovakia\Loxone\Exceptions\ControlException;
use Goslovakia\Loxone\Exceptions\RequestIpException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LoxoneManager
{
    private Client $client;
    private string $serialNumber;
    private string $miniserverIp;

    public function __construct(string $serialNumber, string $username, string $password)
    {
        $this->serialNumber = $serialNumber;
        $this->client = new Client([
            'verify' => false, // Disable SSL validation because some endpoints return no/wrong certificate
            'http_errors' => false, // Disable Guzzle exceptions so we can send our custom exceptions
            'auth' => [$username, $password], // HTTP Auth
            'timeout' => 10,
            'connect_timeout' => 10
        ]);

        $miniserverIp = Cache::get('loxone_' . $serialNumber . '_ip');
        $this->miniserverIp = $miniserverIp ?? $this->requestIp();
    }

    private function requestIp(): string
    {
        $result = $this->client->request('GET', config('loxone.endpoint') . '/?getip&snr=' . $this->serialNumber . '&json=true');
        $statusCode = $result->getStatusCode();

        if ($statusCode == 200) {
            $response = $result->getBody();

            if (json_validate($response)) {
                $dedocedData = json_decode($response, true);

                if (isset($dedocedData['IPHTTPS'])) {
                    Cache::set('loxone_' . $this->serialNumber . '_ip', $dedocedData['IPHTTPS']);

                    return $dedocedData['IPHTTPS'];
                }
            }
        }

        throw new RequestIpException('Failed to retrieve Loxone miniserver IP.', $statusCode);
    }

    public function getMiniserverIp(): string
    {
        return $this->miniserverIp;
    }

    public function getMiniserverInfo(): ?array
    {
        try {
            $result = $this->client->request('GET', 'https://' . $this->miniserverIp . '/data/LoxAPP3.json');
            $statusCode = $result->getStatusCode();
            $response = $result->getBody();

            if ($statusCode == 200 && json_validate($response)) {
                return json_decode($response, true);
            }

            return null;
        } catch (ConnectException $e) {
            $this->miniserverIp = $this->requestIp();

            return $this->getMiniserverInfo();
        }
    }

    public function reboot(): bool
    {
        try {
            $result = $this->client->request('GET', 'https://' . $this->miniserverIp . '/dev/sys/reboot');
            $statusCode = $result->getStatusCode();

            return $statusCode == 200;
        } catch (ConnectException $e) {
            $this->miniserverIp = $this->requestIp();

            return $this->reboot();
        }
    }

    public function getSwitchState(string $uuid): bool
    {
        try {
            $result = $this->client->request('GET', 'https://' . $this->miniserverIp . '/jdev/sps/io/' . $uuid . '/state');
            $statusCode = $result->getStatusCode();
            $response = $result->getBody();

            if ($statusCode == 200 && json_validate($response)) {
                $data = json_decode($response, true);

                if (isset($data['LL']['value'])) {
                    return intval($data['LL']['value']) == 1;
                }
            }

            throw new ControlException('Failed to read switch state.', $statusCode);
        } catch (ConnectException $e) {
            Log::error($e);

            $this->miniserverIp = $this->requestIp();

            return $this->getSwitchState($uuid);
        }
    }

    public function setSwitchState(string $uuid, bool $state): bool
    {
        try {
            $result = $this->client->request('GET', 'https://' . $this->miniserverIp . '/jdev/sps/io/' . $uuid . '/' . ($state ? 'on' : 'off'));
            $statusCode = $result->getStatusCode();
            $response = $result->getBody();

            if ($statusCode == 200 && json_validate($response)) {
                $data = json_decode($response, true);

                if (isset($data['LL']['value'])) {
                    return $data['LL']['value'] == '1';
                }
            }

            throw new ControlException('Failed to change switch state.', $statusCode);
        } catch (ConnectException $e) {
            Log::error($e);

            $this->miniserverIp = $this->requestIp();

            return $this->setSwitchState($uuid, $state);
        }
    }

    public function getControlValue(string $uuid): string
    {
        try {
            $result = $this->client->request('GET', 'https://' . $this->miniserverIp . '/jdev/sps/io/' . $uuid . '/state');
            $statusCode = $result->getStatusCode();
            $response = $result->getBody();

            if ($statusCode == 200 && json_validate($response)) {
                $data = json_decode($response, true);

                if (isset($data['LL']['value'])) {
                    return $data['LL']['value'];
                }
            }

            throw new ControlException('Failed to read control value.', $statusCode);
        } catch (ConnectException $e) {
            Log::error($e);

            $this->miniserverIp = $this->requestIp();

            return $this->getControlValue($uuid);
        }
    }

    public function setControlValue(string $uuid, string $value): bool
    {
        try {
            $result = $this->client->request('GET', 'https://' . $this->miniserverIp . '/jdev/sps/io/' . $uuid . '/' . $value);
            $statusCode = $result->getStatusCode();
            $response = $result->getBody();

            if ($statusCode == 200 && json_validate($response)) {
                $data = json_decode($response, true);

                if (isset($data['LL']['value'])) {
                    return $data['LL']['value'] == $value;
                }
            }

            throw new ControlException('Failed to set control value.', $statusCode);
        } catch (ConnectException $e) {
            Log::error($e);

            $this->miniserverIp = $this->requestIp();

            return $this->setControlValue($uuid, $value);
        }
    }

    public function activatePushButton(string $uuid): bool
    {
        try {
            $result = $this->client->request('GET', 'https://' . $this->miniserverIp . '/jdev/sps/io/' . $uuid . '/pulse');
            $statusCode = $result->getStatusCode();
            $response = $result->getBody();

            if ($statusCode == 200 && json_validate($response)) {
                $data = json_decode($response, true);

                if (isset($data['LL']['value'])) {
                    return $data['LL']['value'] == '1';
                }
            }

            throw new ControlException('Failed to activate push button.', $statusCode);
        } catch (ConnectException $e) {
            Log::error($e);

            $this->miniserverIp = $this->requestIp();

            return $this->activatePushButton($uuid);
        }
    }

    public function setRadioValue(string $uuid, string $value): bool
    {
        try {
            $result = $this->client->request('GET', 'https://' . $this->miniserverIp . '/jdev/sps/io/' . $uuid . '/' . $value);
            $statusCode = $result->getStatusCode();
            $response = $result->getBody();

            if ($statusCode == 200 && json_validate($response)) {
                $data = json_decode($response, true);

                if (isset($data['LL']['value'])) {
                    return $data['LL']['value'] == '1';
                }
            }

            throw new ControlException('Failed to set radio value.', $statusCode);
        } catch (ConnectException $e) {
            Log::error($e);

            $this->miniserverIp = $this->requestIp();

            return $this->setRadioValue($uuid, $value);
        }
    }
}
