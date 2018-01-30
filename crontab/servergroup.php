<?php
/**
 * 分布式多数据中心的时候
 * 增加专门的服务器集群控制的接口，来完成数据的更新
 * 需要更新的数据：
 * 1 平衡商品的库存数量
 * 2 更新商品的剩余数量
 * 3 更新商品的状态
 * 自动平衡商品ID=3的库存
 * php servergroup.php get 3
 *
 * 给127.0.0.1:8082服务器集群的商品ID=3增加10个库存
 * php servergroup.php update_left 3 100 127.0.0.1
 *
 * 给127.0.0.1:8082服务器集群的商品ID=3状态更新为已售完
 * php servergroup.php update_status 3 1 127.0.0.1
 * @author wangyi
 */

include 'init.php';

$server_list = array(
    '127.0.0.1',
    '127.0.0.1',
    '127.0.0.1',
);
$group_url = '/servergroup.php';

$action = $argv[1];
$goods_id = intval($argv[2]);

if (!$action || !$goods_id) {
    echo "args error, php servergroup.php get 3\n";
    exit();
}

$curl_obj = new \Curl\Curl();

if ('update_left' == $action) {
    $num_left = intval($argv[3]);
    $server = $argv[4];
    if (!$server) {
        echo "args error, php servergroup.php update_left 3 100 127.0.0.1:8082\n";
        exit();
    }
    // 调用指定服务器的接口
    $api = 'http://' . $server . $group_url . '?action=update_left&id=' . $goods_id . '&num_left=' . $num_left;
    $str = $curl_obj->get($api);
    $data = json_decode($str, true);
    print_r($data);
    if ($data && $data['id']) {
        echo "crontab server group update_left success.$api \n";
    } else {
        echo "crontab server group update_left error.$api \n";
    }
} elseif ('update_status' == $action) {
    $status = intval($argv[3]);
    $server = $argv[4];
    if (!$server) {
        echo "args error, php servergroup.php update_status 3 1 127.0.0.1:8082\n";
        exit();
    }
    // 调用指定服务器的接口
    $api = 'http://' . $server . $group_url . '?action=update_status&id=' . $goods_id . '&status=' . $status;
    $str = $curl_obj->get($api);
    $data = json_decode($str, true);
    if ($data && $data['id']) {
        echo "crontab server group update_status success.$api \n";
    } else {
        echo "crontab server group update_status error.$api \n";
    }
} else {
    // 获取当前数据中心的商品商品数量，根据策略自动平衡库存
    $max = 0;
    $min = 0;
    $left_list = array();
    foreach ($server_list as $server) {
        $api = 'http://' . $server . $group_url . '?action=get&id=' . $goods_id;
        $str = $curl_obj->get($api);
        $data = json_decode($str, true);
        $id = $data['id'];
        $num_left = intval($data['num_left']);
        echo "crontab servergroup get balance.$api ,num_left=$num_left \n";
        $left_list[] = $num_left;
        $max = max($max, $num_left);
        if ($num_left > 0) {
            // 最小值比0还小就没有意义，都是库存不足的状态
            if ($min < 1) {
                $min = $num_left;
            } else {
                $min = min($min, $num_left);
            }
        }
    }
    // max 和 min 相差悬殊的时候，才考虑做更新
    if ($max - $min > 1000) {
        // 这个数值，根据秒杀规模有不同的设定
        // 从最大库存的服务器，拿一半给到最少的服务器
        $server_max = array();
        $server_min = array();
        $total_num = 0;
        $server_num = 0;
        foreach ($left_list as $i => $left) {
            if ($left == $max) {
                $server_max[] = $i;
                $total_num += $max;
                $server_num++;
            }
            if ($left == $min) {
                $server_min[] = $i;
                $total_num += $min;
                $server_num++;
            }
        }
        $avg = floor($total_num / $server_num);
        $max_less = $max - $avg;
        $min_more_total = $max_less * count($server_max);
        // 先对服务器扣减库存
        foreach ($server_max as $i) {
            $server = $server_list[$i];
            $api = 'http://' . $server . $group_url . '?action=update_left&id=' . $goods_id . '&num_left=' . (0-$max_less);
            $str = $curl_obj->get($api);
            $data = json_decode($str, true);
            if ($data['left'] && $data['left'] < 0) {
                // 扣减的时候，已经出现超卖，后面的服务器就不能再增加那么多的数量了
                $min_more_total += $data['left'];
                echo "crontab servergroup exception.$api exception response \n";
            } elseif (!$data || !$data['id']) {
                // 接口出现错误，不能给其它服务器增加库存数量
                $min_more_total -= $max_less;
                echo "crontab servergroup exception.$api exception response \n";
            } else {
                echo "crontab server group success.$api \n";
            }
        }
        // 库存扣减成功了再对服务器增加库存
        $min_more = floor($min_more_total / count($server_min));
        // 整数处理的过程中，还有遗漏的库存，直接放到第一个服务器
        $first_min = $min_more_total > ($min_more * count($server_min));

        foreach ($server_min as $i) {
            $server = $server_list[$i];
            $num = $min_more;
            if ($i = 0 && $first_min > 0) {
                $num += $first_min;
            }
            $api = 'http://' . $server . $group_url . '?action=update_left&id=' . $goods_id . '&num_left=' . $num;
            $str = $curl_obj->get($api);
            $data = json_decode($str, true);
            if (!$data || !$data['id']) {
                // 接口出现错误，重试或者记录异常信息，以便后续继续处理
                echo "crontab servergroup error.$api error response \n";
            } else {
                echo "crontab server group success.$api \n";
            }
        }
    } else {
        echo "crontab server group get.no data need to update\n";
    }
}
