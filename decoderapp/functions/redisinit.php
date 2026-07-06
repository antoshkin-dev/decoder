<?php
use Redis;
$redis = new Redis();
//Connecting to Redis
$redis->connect('decoder-redis', 6379);
const SessionTTL=60; 

if (!$redis->ping()) 
{
    die ("REDIS not ready\r\n");
}
