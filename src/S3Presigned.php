<?php

namespace Unisharp\S3\Presigned;

use Aws\S3\S3Client;
use Aws\S3\PostObjectV4;
use Exception;

class S3Presigned
{
    protected $client;
    protected $bucket;
    protected $baseUri;
    protected $prefix;

    public function __construct(S3Client $client, $bucket = null, $prefix = null)
    {
        $this->client = $client;
        $this->bucket = $bucket ?: env('AWS_S3_BUCKET');
        $this->prefix = $prefix;
        $this->initBaseUri();
    }

    public function getUrl($minutes = 5)
    {
        // http://docs.aws.amazon.com/aws-sdk-php/v3/api/api-s3-2006-03-01.html#putobject
        // http://docs.aws.amazon.com/AmazonS3/latest/API/sigv4-query-string-auth.html
        $cmd = $this->client->getCommand('PutObject', [
            'Bucket' => $this->bucket,
            'Key' => $this->getPrefix() . uniqid() . '.jpg',
            'ACL' => 'public-read'
        ]);
        $request = $this->client
            ->createPresignedRequest($cmd, "+{$minutes} minutes");

        return (string) $request->getUri();
    }

    public function getFields($minutes = 5)
    {
        // https://aws.amazon.com/tw/articles/browser-uploads-to-s3-using-html-post-forms/
        // http://docs.aws.amazon.com/AmazonS3/latest/API/sigv4-post-example.html
        $key = $this->getPrefix() . uniqid();
        $defaults = [
            'acl' => 'public-read',
            'key' => $key . '-${filename}',
            // 'Content-Type' => '',
            // 'file' => ''
        ];
        $options = [
            ['acl' => 'public-read'],
            ['bucket' => $this->bucket],
            ['starts-with', '$key', $key],
            ['content-length-range', 0, 5242895],
            ['starts-with', '$Content-Type', 'image/'],
            // ['content-type-starts-with' => 'image/'],
        ];
        $postObject = $this->getPostObject($defaults, $options, $minutes);

        return $postObject->getFormInputs();
    }

    public function listObjects()
    {
        // http://docs.aws.amazon.com/AmazonS3/latest/dev/ListingObjectKeysUsingPHP.html
        // http://docs.aws.amazon.com/aws-sdk-php/v3/api/api-s3-2006-03-01.html#listobjects
        // https://github.com/thephpleague/flysystem-aws-s3-v3/blob/master/src/AwsS3Adapter.php
        $resultPaginator = $this->client->getPaginator('ListObjects', [
            'Bucket' => $this->bucket,
            'Prefix' => $this->getPrefix()
        ]);

        $listing = [];
        foreach ($resultPaginator as $result) {
            $objects = $result->get('Contents');
            if (is_null($objects)) {
                continue;
            }
            foreach ($objects as $object) {
                $listing[] = $this->normalizeObject($object);
            }
        }

        return $listing;
    }

    public function deleteObject($key)
    {
        return $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key'    => $key
        ]);
    }

    private function normalizeObject(array $object)
    {
        $normalized = [];
        $normalized['key'] = $object['Key'] ?? '';
        $normalized['url'] = $this->baseUri . $normalized['key'];
        $normalized['size'] = $object['Size'] ?? '';

        return $normalized;
    }

    private function getPostObject(array $defaults, array $options, $minutes = 10)
    {
        return new PostObjectV4(
            $this->client,
            env('AWS_S3_BUCKET'),
            $defaults,
            $options,
            "+{$minutes} minutes"
        );
    }

    private function initBaseUri()
    {
        $this->baseUri = 'https://s3-' . env('AWS_S3_REGION') . ".amazonaws.com/{$this->bucket}/";
    }

    private function getPrefix()
    {
        $userId = auth()->user() ? auth()->user()->id : 'public';
        $prefix = 'users/' . $userId . '/';
        if (!empty($this->prefix)) {
            $prefix = $this->prefix . '/' . $prefix;
        }

        return $prefix;
    }
}