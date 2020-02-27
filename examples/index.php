<?php

require __DIR__ . '/vendor/autoload.php';

use MKCG\Examples\SocialNetwork\Schema;
use MKCG\Model\DBAL\FilterInterface;
use MKCG\Model\DBAL\QueryCriteria;
use MKCG\Model\DBAL\Drivers;
use MKCG\Model\DBAL\QueryEngine;

$redisClient = new \Predis\Client(['scheme' => 'tcp', 'host' => 'redisearch', 'port' => 6379]);
$httpClient = new \Guzzle\Http\Client('http://elasticsearch:9200/');
$sqlConnection = \Doctrine\DBAL\DriverManager::getConnection([
    'user' => 'root',
    'password' => 'root',
    'host' => 'mysql',
    'driver' => 'pdo_mysql',
]);

$fixturePath = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures';

// createFakeData($sqlConnection, $fixturePath . DIRECTORY_SEPARATOR);

$engine = new \MKCG\Model\DBAL\QueryEngine('mysql');
$engine->registerDriver(new Drivers\Doctrine($sqlConnection), 'mysql');
$engine->registerDriver(new Drivers\CsvReader($fixturePath), 'csv');

$startedAt = microtime(true);

searchUsers($engine);
searchOrder($engine);

$took = microtime(true) - $startedAt;
echo "Took : " . round($took, 3) . "s\n";

function searchOrder(QueryEngine $engine)
{
    $model = Schema\Order::make('default', 'order')
        ->with(Schema\User::make()
            ->with(Schema\Address::make())
            ->with(Schema\Post::make())
        )
    ;

    $criteria = (new QueryCriteria())
        ->forCollection('order')
            ->addFilter('firstname', FilterInterface::FILTER_FULLTEXT_MATCH, 'al')
            ->addFilter('price', FilterInterface::FILTER_GREATER_THAN_EQUAL, 15)
            ->addFilter('price', FilterInterface::FILTER_GREATER_THAN, 10)
            ->addFilter('vat', FilterInterface::FILTER_IN, [ 10, 20 ])
            ->addFilter('credit_card_type', FilterInterface::FILTER_NOT_IN, ['Visa', 'Visa Retired'])
        ->forCollection('addresses')
            ->setLimitByParent(3)
        ->forCollection('posts')
            ->setLimitByParent(2)
        ;

    $orders = [];

    foreach ($engine->scroll($model, $criteria) as $i => $order) {
        $orders[] = $order;
    }

    echo json_encode($orders, JSON_PRETTY_PRINT) . "\n\n";
    echo "Found : " . count($orders) . " items\n\n";
}

function searchUsers(QueryEngine $engine)
{
    $model = Schema\User::make('default', 'user')
        ->with(Schema\Address::make())
        ->with(Schema\Post::make());

    $criteria = (new QueryCriteria())
        ->forCollection('user')
            ->addFilter('status', FilterInterface::FILTER_IN, [ 2 , 3 , 5 , 7 ])
            ->addFilter('registered_at', FilterInterface::FILTER_GREATER_THAN_EQUAL, '2000-01-01')
            ->addSort('firstname', 'ASC')
            ->addSort('lastname', 'ASC')
            ->setLimit(10)
        ->forCollection('addresses')
            ->setLimitByParent(2)
        ->forCollection('posts')
            ->addFilter('title', FilterInterface::FILTER_FULLTEXT_MATCH, 'ab')
    ;

    $users = $engine->query($model, $criteria);

    echo json_encode($users->getContent(), JSON_PRETTY_PRINT) . "\n";
    echo "\nFound : " . $users->getCount() . " users\n";

    $iterator = $engine->scroll($model, $criteria);

    foreach ($iterator as $i => $user) {
        echo json_encode($user, JSON_PRETTY_PRINT) . "\n";
    }
}

function createFakeData(\Doctrine\DBAL\Connection $connection, string $fixturePath)
{
    createDatabaseSchema($connection, [ new Schema\User(), new Schema\Address(), new Schema\Post() ]);

    $faker = \Faker\Factory::create();

    $csvOrderHandler = fopen($fixturePath . (new Schema\Order())->getFullyQualifiedName(), 'w+');
    fputcsv($csvOrderHandler, [
        'id',
        'id_user',
        'firstname',
        'lastname',
        'credit_card_type',
        'credit_card_number',
        'price',
        'vat',
        'currency'
    ]);

    $userCounter = 0;
    $addressCounter = 0;
    $postCounter = 0;
    $orderCounter = 0;

    $statements = '';

    for ($i = 1; $i <= 1000; $i++) {
        $user = [
            'id' => ++$userCounter,
            'firstname' => $faker->firstName,
            'lastname' => $faker->lastName,
            'email' => $faker->email,
            'phone' => $faker->e164PhoneNumber,
            'registered_at' => $faker->date,
            'status' => $faker->numberBetween(0, 10)
        ];

        for ($j = 0; $j < 20; $j++) {
            fputcsv($csvOrderHandler, [
                ++$orderCounter,
                $user['id'],
                $user['firstname'],
                $user['lastname'],
                $faker->creditCardType,
                $faker->creditCardNumber,
                rand(1, 100),
                [5, 10, 20][rand(0, 2)],
                $faker->currencyCode
            ]);

            if (rand(0, 10) < 3) {
                break;
            }
        }

        $query = sprintf(
            "INSERT INTO socialnetwork.user
                (id, firstname, lastname, email, phone, registered_at, status)
            VALUES (%d , %s , %s, %s, %s , %s, %d );",
            $user['id'],
            $connection->quote($user['firstname']),
            $connection->quote($user['lastname']),
            $connection->quote($user['email']),
            $connection->quote($user['phone']),
            $connection->quote($user['registered_at']),
            $user['status']
        );

        $statements .= $query . "\n";
        // $connection->exec($query);

        for ($j = 0; $j < 5; $j++) {
            $address = [
                'id' => ++$addressCounter,
                'id_user' => $i,
                'street' => $faker->streetName,
                'postcode' => $faker->postcode,
                'city' => $faker->city,
                'country' => $faker->country
            ];

            $query = sprintf(
                "INSERT INTO socialnetwork.address
                    (id, id_user, street, postcode, city, country)
                VALUES (%d , %d , %s, %s, %s, %s);",
                $address['id'],
                $address['id_user'],
                $connection->quote($address['street']),
                $connection->quote($address['postcode']),
                $connection->quote($address['city']),
                $connection->quote($address['country']),
            );

            $statements .= $query . "\n";
            // $connection->exec($query);

            if (rand(0, 5) < 2) {
                break;
            }
        }

        for ($k = 0; $k < 10; $k++) {
            $post = [
                'id' => ++$postCounter,
                'id_user' => $userCounter,
                'published_at' => $faker->date,
                'title' => $faker->sentence,
                'content' => $faker->paragraphs(rand(3, 6), true)
            ];

            $query = sprintf(
                "INSERT INTO socialnetwork.post
                    (id, id_user, published_at, title, content)
                VALUES (%d , %d , %s, %s, %s);",
                $post['id'],
                $post['id_user'],
                $connection->quote($post['published_at']),
                $connection->quote($post['title']),
                $connection->quote($post['content'])
            );

            $statements .= $query . "\n";


            if (rand(0, 10) < 2) {
                break;
            }
        }

        if ($i % 50 === 0) {
            $connection->exec($statements);
            echo "Created : ${userCounter} users - ${addressCounter} addresses - ${postCounter} posts - ${orderCounter} orders\n";
            $statements = '';
        }
    }

    if ($statements !== '') {
        $connection->exec($statements);
        echo "Created : ${userCounter} users - ${addressCounter} addresses - ${postCounter} posts - ${orderCounter} orders\n";
    }

    fclose($csvOrderHandler);
}

function createDatabaseSchema(\Doctrine\DBAL\Connection $connection, array $schema)
{
    $databases = $connection->query('SHOW DATABASES;')->fetchAll(\PDO::FETCH_COLUMN);

    if (in_array('socialnetwork', $databases)) {
        $connection->exec('DROP DATABASE socialnetwork;');
        $databases = $connection->query('SHOW DATABASES;')->fetchAll(\PDO::FETCH_COLUMN);
    }

    foreach ($schema as $scheme) {
        list($database, $table) = explode('.', $scheme->getFullyQualifiedName());

        if (!in_array($database, $databases)) {
            $connection->exec('CREATE DATABASE ' . $database);
            $databases[] = $database;
        }

        $connection->exec('USE ' . $database);

        $tables = $connection->query('SHOW TABLES;')->fetchAll(\PDO::FETCH_COLUMN);

        if (in_array($table, $tables)) {
            // var_dump($connection->query('DESCRIBE ' . $table)->fetchAll());
            continue;
        }

        $statement = '';

        switch ($table) {
            case 'address':
                $statement = 'CREATE TABLE address (
                    id int PRIMARY KEY,
                    id_user int NOT NULL,
                    street varchar(255),
                    postcode varchar(20),
                    city varchar(100),
                    country varchar(100),
                    FOREIGN KEY (id_user)
                        REFERENCES user (id)
                        ON UPDATE RESTRICT ON DELETE CASCADE
                ) ENGINE=INNODB';
                break;

            case 'post':
                $statement = 'CREATE TABLE post (
                    id int PRIMARY KEY,
                    id_user int NOT NULL,
                    published_at DATE,
                    title varchar(255),
                    content mediumtext,
                    FOREIGN KEY (id_user)
                        REFERENCES user (id)
                        ON UPDATE RESTRICT ON DELETE CASCADE
                ) ENGINE=INNODB';

                break;

            case 'user':
                $statement = 'CREATE TABLE user (
                    id int PRIMARY KEY,
                    firstname varchar(50) NOT NULL,
                    lastname varchar(50) NOT NULL,
                    email varchar(50),
                    phone varchar(30),
                    registered_at DATE,
                    status int DEFAULT 0
                ) ENGINE=INNODB';
                break;
        }

        $connection->exec($statement);
    }
}