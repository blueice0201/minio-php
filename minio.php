<?php
/**
 * minio 存储操作
 * @author blic
 * */
namespace minio;
class Minio
{
    const CODE_SUCCESS = 200;
    const CODE_DEL_SUCCESS = 204;
    private $accessKey;
    private $secretKey;
    private $endpoint;
    private $bucket;
    private $domain;
    private $multiCurl;
    private $curlOpts = [
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_LOW_SPEED_LIMIT => 1,
        CURLOPT_LOW_SPEED_TIME => 30
    ];
    private static $instance;

    public function __construct()
    {
        $_config = get_minio_config(); // 特有函数
        $this->accessKey = $_config['accessKey'];
        $this->secretKey = $_config['secretKey'];
        $this->endpoint = $_config['endpoint'];
        $this->bucket = $_config['bucket'];
        $this->domain = $_config['domain'];
        if (empty($this->bucket)) $this->bucket = 'default';
        $this->multiCurl = curl_multi_init();
    }

    public function __destruct()
    {
        curl_multi_close($this->multiCurl);
    }

    /**
     * 单例模式 获取实例
     * @return Minio
     */
    public static function getInstance()
    {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 设置当前桶
     */
    public function setBucket($bucket)
    {
        $this->bucket = $bucket;
        return $this;
    }

    /**
     * 获取bucket列表
     * @param boolean $with_headers 是否返回header信息
     */
    public function listBuckets($with_headers = false)
    {
        $res = $this->requestBucket('GET', '');
        if ($res['code'] == self::CODE_SUCCESS) {
            if (isset($res['data']['Buckets']['Bucket']['Name'])) { // 只有一个bucket的情况下
                $_buckets = [$res['data']['Buckets']['Bucket']['Name']];
            } else { // 多个 bucket
                $_buckets = array_column($res['data']['Buckets']['Bucket'], 'Name');
            }
            $res['data'] = ['Buckets' => $_buckets];
            return $this->success('获取成功！', $with_headers ? $res : $res['data']);
        } else {
            return $this->error($res['data']['Message'], $res['code'], $res['data']);
        }
    }

    /**
     * 获取bucket目录文件信息
     * @param string $bucket 桶名称
     * @param boolean $with_headers 是否返回header信息
     */
    public function getBucket(string $bucket, $with_headers = false)
    {
        $res = $this->requestBucket('GET', $bucket);
        if ($res['code'] == self::CODE_SUCCESS) {
            if (isset($res['data']['Contents']['Key'])) $res['data']['Contents'] = [$res['data']['Contents']]; // 单个文件
            return $this->success('获取成功！', $with_headers ? $res : $res['data']);
        } else {
            return $this->error($res['data']['Message'], $res['code'], $res['data']);
        }
    }

    /**
     * 创建bucket目录
     * @param string $bucket 桶名称
     */
    public function createBucket(string $bucket)
    {
        $res = $this->requestBucket('PUT', $bucket);
        return $res['code'] == self::CODE_SUCCESS;
    }

    /**
     * 删除bucket目录
     * @param string $bucket 桶名称
     */
    public function deleteBucket(string $bucket)
    {
        $res = $this->requestBucket('DELETE', $bucket);
        return $res['code'] == self::CODE_SUCCESS;
    }

    /**
     * 上传文件
     * @param string $file 本地需要上传的绝对路径文件
     * @param string $uri 保存路径名称，不包含桶名称
     */
    public function putObject(string $file, string $uri, $with_headers = false)
    {
        // 判断bucket是否存在，不存在则创建
        $rel = $this->listBuckets();
        if ($rel['status']) return $rel;
        if (!in_array($this->bucket, $rel['data']['Buckets'])) {
            $this->createBucket($this->bucket);
        }

        // 发送请求
        $request = (new Request('PUT', $this->endpoint, $this->getObjectUri($uri)))
            ->setFileContents(fopen($file, 'r'))
            ->setMultiCurl($this->multiCurl)
            ->setCurlOpts($this->curlOpts)
            ->sign($this->accessKey, $this->secretKey);

        $res = $this->objectToArray($request->getResponse());

        if ($res['code'] == self::CODE_SUCCESS) {
            return $this->success('上传成功！', $with_headers ? $res : $res['data']);
        } else {
            return $this->error($res['data']['Message'], $res['code'], $res['data']);
        }
    }

    /**
     * 获取文件链接
     * @param string $uri 保存路径名称
     */
    public function getObjectUrl(string $uri)
    {
        return trim($this->domain, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $this->getObjectUri($uri);
    }

    /**
     * 获取文件地址
     * @param string $uri 保存路径名称
     */
    public function getObjectUri(string $uri)
    {
        return trim($this->bucket, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . trim($uri, DIRECTORY_SEPARATOR);
    }

    /**
     * 获取文件类型，header中体现
     * @param string $uri 保存路径名称
     */
    public function getObjectInfo(string $uri)
    {
        $request = (new Request('HEAD', $this->endpoint, $this->getObjectUri($uri)))
            ->setMultiCurl($this->multiCurl)
            ->setCurlOpts($this->curlOpts)
            ->sign($this->accessKey, $this->secretKey);

        $res = $this->objectToArray($request->getResponse());

        if ($res['code'] == self::CODE_SUCCESS) {
            return $this->success('获取成功！', $res['headers']);
        } else {
            return $this->error($res['data']['Message'], $res['code'], $res['data']);
        }
    }

    /**
     * 获取文件 ，data返回二进制数据流
     * @param string $uri 保存路径名称
     * @param boolean $with_headers 是否返回header信息
     */
    public function getObject(string $uri, $with_headers = false)
    {
        $request = (new Request('GET', $this->endpoint, $this->getObjectUri($uri)))
            ->setMultiCurl($this->multiCurl)
            ->setCurlOpts($this->curlOpts)
            ->sign($this->accessKey, $this->secretKey);

        $res = $this->objectToArray($request->getResponse());

        if ($res['code'] == self::CODE_SUCCESS) {
            return $this->success('获取成功！', $with_headers ? $res : $res['data']);
        } else {
            return $this->error($res['data']['Message'], $res['code'], $res['data']);
        }
    }

    /**
     * 删除文件
     * @param string $uri 保存路径名称
     */
    public function deleteObject(string $uri)
    {
        $request = (new Request('DELETE', $this->endpoint, $this->getObjectUri($uri)))
            ->setMultiCurl($this->multiCurl)
            ->setCurlOpts($this->curlOpts)
            ->sign($this->accessKey, $this->secretKey);

        $res = $this->objectToArray($request->getResponse());
        return $res['code'] == self::CODE_DEL_SUCCESS;
    }

    /**
     * 复制文件
     * @param string $fromObject 源文件
     * @param string $toObject 目标文件
     */
    public function copyObject(string $fromObject, string $toObject)
    {
        $request = (new Request('PUT', $this->endpoint, $this->getObjectUri($toObject)))
            ->setHeaders(['x-amz-copy-source' => $this->getObjectUri($fromObject)])
            ->setMultiCurl($this->multiCurl)
            ->setCurlOpts($this->curlOpts)
            ->sign($this->accessKey, $this->secretKey);

        $res = $this->objectToArray($request->getResponse());

        return $res['code'] == self::CODE_SUCCESS;
    }

    /**
     * 移动文件
     * @param string $fromObject 源文件
     * @param string $toObject 目标文件
     * */
    public function moveObject(string $fromObject, string $toObject)
    {
        // 复制文件
        $res = $this->copyObject($fromObject, $toObject);
        if ($res) {
            // 删除源文件
            $res2 = $this->deleteObject($fromObject);
        }
        return $res && $res2;
    }

    /**
     * bucket目录请求
     * @param string $method
     * @param string $bucket
     * @param array $headers
     * @return mixed
     */
    protected function requestBucket(string $method = 'GET', string $bucket = '')
    {
        $request = (new Request($method, $this->endpoint, $bucket))
            ->setMultiCurl($this->multiCurl)
            ->setCurlOpts($this->curlOpts)
            ->sign($this->accessKey, $this->secretKey);

        return $this->objectToArray($request->getResponse());
    }

    /**
     * 对象转数组
     * @param $object
     * @return mixed
     */
    private function objectToArray($object)
    {
        $arr = is_object($object) ? get_object_vars($object) : $object;
        $returnArr = [];
        foreach ($arr as $key => $val) {
            $val = (is_array($val)) || is_object($val) ? $this->objectToArray($val) : $val;
            $returnArr[$key] = $val;
        }
        return $returnArr;
    }

    private function success($msg = '操作成功', $data = [])
    {
        return ['status' => 0, 'msg' => $msg, 'data' => $data];
    }

    private function error($msg = '出错了', $status = 1, $data = [])
    {
        return ['status' => $status, 'msg' => $msg, 'data' => $data];
    }

}

class Request
{
    private $action;
    private $endpoint;
    private $uri;
    private $headers;
    private $curl;
    private $response;
    private $multi_curl;
    private $secure;

    public function __construct($action, $endpoint, $uri)
    {
        $this->action = $action;
        $this->uri = $uri;
        $url = parse_url($endpoint);
        $this->endpoint = $url['host'];
        if (!empty($url['port'])) {
            $this->endpoint = $url['host'] . ':' . $url['port'];
        }
        $url['scheme'] === 'http' || $this->secure = true;
        $this->headers = [
            'Content-MD5' => '',
            'Content-Type' => '',
            'Date' => gmdate('D, d M Y H:i:s T'),
            'Host' => $this->endpoint,
        ];

        $this->curl = curl_init();
        $this->response = new Response();

        $this->multi_curl = null;
    }

    /**
     * 设置文件内容
     * @param $file
     * @return $this
     */
    public function setFileContents($file)
    {
        if (is_resource($file)) {
            $hash_ctx = hash_init('md5');
            $length = hash_update_stream($hash_ctx, $file);
            $md5 = hash_final($hash_ctx, true);

            rewind($file);
            $this->headers['Content-Type'] = mime_content_type($file);

            curl_setopt($this->curl, CURLOPT_PUT, true);
            curl_setopt($this->curl, CURLOPT_INFILE, $file);
            curl_setopt($this->curl, CURLOPT_INFILESIZE, $length);
        } else {
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, $file);
            $md5 = md5($file, true);
        }
        $this->headers['Content-MD5'] = base64_encode($md5);
        return $this;
    }

    /**
     * 设置headers
     * @param $headers
     * @return $this
     */
    public function setHeaders($headers)
    {
        if (!empty($headers)) {
            $this->headers = array_merge($this->headers, $headers);
        }
        return $this;
    }

    /**
     * 生成签名
     * @param $access_key
     * @param $secret_key
     * @return $this
     */
    public function sign($access_key, $secret_key)
    {
        $canonical_amz_headers = $this->getCanonicalAmzHeaders();
        $string_to_sign = '';
        $string_to_sign .= "{$this->action}\n";
        $string_to_sign .= "{$this->headers['Content-MD5']}\n";
        $string_to_sign .= "{$this->headers['Content-Type']}\n";
        $string_to_sign .= "{$this->headers['Date']}\n";

        if (!empty($canonical_amz_headers)) {
            $string_to_sign .= implode("\n", $canonical_amz_headers) . "\n";
        }

        $string_to_sign .= "/{$this->uri}";
        $signature = base64_encode(
            hash_hmac('sha1', $string_to_sign, $secret_key, true)
        );

        $this->headers['Authorization'] = "AWS $access_key:$signature";
        return $this;
    }

    /**
     * multi_curl设置
     * @param $mh
     * @return $this
     */
    public function setMultiCurl($mh)
    {
        $this->multi_curl = $mh;
        return $this;
    }

    /**
     * opt设置
     * @param $curl_opts
     * @return $this
     */
    public function setCurlOpts($curl_opts)
    {
        curl_setopt_array($this->curl, $curl_opts);

        return $this;
    }

    /**
     * 获取返回内容
     * @return Response
     */
    public function getResponse()
    {
        $http_headers = array_map(
            function ($header, $value) {
                return "$header: $value";
            },
            array_keys($this->headers),
            array_values($this->headers)
        );
        $url = "{$this->endpoint}/{$this->uri}";
        if ($this->secure) {
            $url = 'https://' . $url;
        } else {
            $url = 'http://' . $url;
        }
        curl_setopt_array($this->curl, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $http_headers,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_WRITEFUNCTION => [
                $this->response, '__curlWriteFunction'
            ],
            CURLOPT_HEADERFUNCTION => [
                $this->response, '__curlHeaderFunction'
            ]
        ]);

        switch ($this->action) {
            case 'DELETE':
                curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
            case 'HEAD':
                curl_setopt($this->curl, CURLOPT_NOBODY, true);
                break;
            case 'POST':
                curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'POST');
                break;
            case 'PUT':
                curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'PUT');
                break;
        }

        if (isset($this->multi_curl)) {
            curl_multi_add_handle($this->multi_curl, $this->curl);

            $running = null;
            do {
                curl_multi_exec($this->multi_curl, $running);
                curl_multi_select($this->multi_curl);
            } while ($running > 0);

            curl_multi_remove_handle($this->multi_curl, $this->curl);
        } else {
            curl_exec($this->curl);
        }

        $this->response->finalize($this->curl);
        curl_close($this->curl);
        return $this->response;
    }

    /**
     * 头部处理
     * @return array
     */
    private function getCanonicalAmzHeaders()
    {
        $canonical_amz_headers = [];
        foreach ($this->headers as $header => $value) {
            $header = trim(strtolower($header));
            $value = trim($value);

            if (strpos($header, 'x-amz-') === 0) {
                $canonical_amz_headers[$header] = "$header:$value";
            }
        }
        ksort($canonical_amz_headers);
        return $canonical_amz_headers;
    }
}

class Response
{
    public $code; //200为成功
    public $error;
    public $headers;
    public $data;

    public function __construct()
    {
        $this->code = null;
        $this->error = null;
        $this->headers = [];
        $this->data = null;
    }

    public function saveToResource($resource)
    {
        $this->data = $resource;
    }

    /**
     * 返回内容回调
     * @param $ch
     * @param $data
     * @return false|int
     */
    public function __curlWriteFunction($ch, $data)
    {
        if (is_resource($this->data)) {
            return fwrite($this->data, $data);
        } else {
            $this->data .= $data;
            return strlen($data);
        }
    }

    /**
     * 头部信息回调
     * @param $ch
     * @param $data
     * @return int
     */
    public function __curlHeaderFunction($ch, $data)
    {
        $header = explode(':', $data);

        if (count($header) == 2) {
            list($key, $value) = $header;
            $this->headers[$key] = trim($value);
        }

        return strlen($data);
    }

    /**
     * 返回内容处理
     * @param $ch
     */
    public function finalize($ch)
    {
        if (is_resource($this->data)) {
            rewind($this->data);
        }

        if (curl_errno($ch) || curl_error($ch)) {
            $this->error = [
                'code' => curl_errno($ch),
                'message' => curl_error($ch),
            ];
        } else {
            $this->code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

            if ($content_type == 'application/xml') {
                $obj = simplexml_load_string($this->data, "SimpleXMLElement", LIBXML_NOCDATA);
                $this->data = json_decode(json_encode($obj), true);
            }
        }
    }
}
