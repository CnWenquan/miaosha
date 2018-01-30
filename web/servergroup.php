<?php
/**
 * 分布式多数据中心的时候
 * 增加专门的服务器集群控制的接口，来完成数据的更新
 * 需要更新的数据：
 * 1 更新商品的剩余数量
 * 2 更新商品的状态
 * 3 查看商品的剩余数量
 * @author wangyi
 */

include 'init.php';

$action = $_GET['action'];
$goods_id = getReqInt('id');

$redis_obj = \common\Datasource::getRedis('instance1');
$goods_model = new \model\Goods();

if ('update_left' == $action) {
    // 更新商品的库存
    $num_left = getReqInt('num_left');
    $key = 'info_g_' . $goods_id;
    $rs = array('id' => $goods_id, 'num_left' => $num_left);
    $left = $redis_obj->hget($key, 'num_left');
    if ($left <= 0 && $num_left > 0) {
        // 当前已经没有库存，直接更新
        $redis_obj->set('st_g_' . $goods_id, 1);
        $left = $redis_obj->hset($key, 'num_left', $num_left);
    } elseif ($left > 0) {
        // 还有库存的时候，需要做原子性更新(递增或递减)
        $left = $goods_model->changeLeftNumCached($goods_id, $num_left);
        if ($num_left > 0 && $left < $num_left) {
            // 增加库存，更新之后的数量小于要增加的数量
            // 说明增加之前已经是一个负数，需要把没有成功加的数量继续放进去
            $num_update = $num_left - $left;
            $left = $goods_model->changeLeftNumCached($goods_id, $num_update);
        } elseif ($num_left < 0 && $left < 0) {
            // 减少库存，更新之后的数量小于0，说明库存数量不够
            // 这个时候不能让其它的服务器增加多余的数量，否则就会超卖
            $redis_obj->del('st_g_' . $goods_id);
            $rs['left'] = $left;
        }
    } elseif ($left <= 0 && $num_left < 0) {
        // 不能继续扣减库存
        $redis_obj->del('st_g_' . $goods_id);
        $rs['left'] = 0 - $num_left;
    }
    echo json_encode($rs);
} elseif ('update_status' == $action) {
    // 更新商品的状态
    $status = getReqInt('status');
    $key = 'st_g_' . $goods_id;
    if ($status) {
        $ok = $redis_obj->set($key, 1);
    } else {    // 状态不可用，同时把库存更新为0
        $ok = $redis_obj->del($key);
        $key = 'info_g_' . $goods_id;
        $ok = $redis_obj->hset($key, 'num_left', 0);
    }
    $rs = array('id' => $goods_id, 'status' => $status, 'ok' => $ok);
    echo json_encode($rs);
} else {
    // 获取当前数据中心的商品数量
    $key = 'info_g_' . $goods_id;
    $num_left = $redis_obj->hget($key, 'num_left');
    $rs = array('id' => $goods_id, 'num_left' => $num_left);
    echo json_encode($rs);
}
