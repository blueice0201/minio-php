# minio-php-sdk
php sdk for minio 


# author
blueice0201


# brief introduction
PHP native code calls API interface to realize the application of Minio resource storage and release

PHP realizes the functions of uploading and deleting operation files, creating, deleting and querying buckets


# Getting Started
```php

// Instantiate an Minio client.
$minioClient = \minio\Minio::getInstance();

// Bucket

$res = $minioClient->listBuckets();

$res = $minioClient->getBucket('default');

$res = $minioClient->createBucket('default');

$res = $minioClient->deleteBucket('default');


// File

// Upload a publicly accessible file. The file size and type are determined by the SDK.
$res = $minioClient->putObject('/filepath/test.jpg', 'default/test.jpg');

$res = $minioClient->getObjectInfo('default/test.jpg');

$res = $minioClient->getObjectUrl('default/test.jpg');

$res = $minioClient->getObject('default/test.jpg');

$res = $minioClient->deleteObject('default/test.jpg');

$res = $minioClient->copyObject('default/test.jpg','default/test1.jpg');

$res = $minioClient->moveObject('default/test1.jpg','default/test2.jpg');

```
