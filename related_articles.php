<?php
/* vim: set shiftwidth=4: */ /*{{{*/
/* vim: set fdm=marker: */
/**
 * 进行pserver相关接口的测试;
 *
 * @author renlu <xurenlu@gmail.com>
 * @version $Id$
 * @copyright renlu <xurenlu@gmail.com>, 02 三月, 2010
 * @package default
 **/
/*
Plugin Name:Related Articles  
Plugin URI: http://www.cloudapi.info/
Description: This plugin provide related articles for each blog aritcle;
Author: 162cm 
Version: 1.0
Author URI: http://www.162cm.com/
 */
/*  Copyright andot (email: xurenlu@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *//*}}}*/
include dirname(__FILE__).'/phprpc/phprpc_client.php';
//如果您想使用单独的资料库,请修改这里的CLOUDAPI_URL里的key和RADB变量;
$ra_apikey=get_option("ra.apikey");
$ra_db=get_option("ra.db");
if(empty($ra_apikey)){
    define("CLOUDAPI_URL","http://cloudapi.info/search_api.py?code=JBMPAH6sZ3e5LN4GRSPzW6bh6irMx7z8");
}
else{
    define("CLOUDAPI_URL","http://cloudapi.info/search_api.py?code=".$ra_apikey);
}
if(empty($ra_db)){
    define("RADB","wordperssblogs");
}
else
{
    define("RADB",$ra_db);
}
define("RATABLE",$table_prefix."ra_table");

/*** {{{  prepare_ra_table 
 * 如果表不存在,需要先创建表;
 */ 
function prepare_ra_table()
{
    global $wpdb,$table_prefix;
    if(!$wpdb->query("desc ".RATABLE)){
        $sql='CREATE TABLE `'.RATABLE.'` (
            `id` bigint(11) unsigned NOT NULL AUTO_INCREMENT,
            `rkey` varchar(64) CHARACTER SET ascii NOT NULL,
            `rvalue` text CHARACTER SET utf8 NOT NULL,
            `flag` tinyint(2) unsigned NOT NULL,
             `expire` bigint(11) DEFAULT \'0\',
            PRIMARY KEY (`id`),
  UNIQUE KEY `idx_rkey` (`rkey`)
  ) ENGINE=MyISAM AUTO_INCREMENT=88 DEFAULT CHARSET=latin1 COMMENT=\'related articles\' ';
        $wpdb->query($sql);
    }
}
/** }}} */
/*** {{{  ra_fetch_row 
 * 从表里取出一行缓存数据;
 */ 
function ra_fetch_row($key)
{
    global $wpdb;
    $sql="select * from ".RATABLE." WHERE rkey='".$key."'  limit 1";
    $rows=$wpdb->get_results($sql);
    if(empty($rows)) return NULL;
    if($rows[0]->flag==1)
        return unserialize($rows[0]->rvalue);
    else
        return $rows[0]->rvalue; 
}
/** }}} */
/*** {{{  ra_set_row 
 * 设置一行缓存数据;
 */ 
function ra_set_row($key,$val)
{
    global $wpdb;
    prepare_ra_table();
    //尝试删掉过期的内容;
    $sql="delete from ".RATABLE ." where expire < ".time(). " " ;
    $wpdb->query($sql);
    if(is_array($val)||is_object($val)){
        $flag="1";
        $value=serialize($val);
    }else{
        $flag="0";
        $value=$val;
    }
    $sql="replace into ".RATABLE." (`rkey`,`rvalue`,`flag`,`expire`) VALUES ('".mysql_escape_string($key)."','".mysql_escape_string($value)."',".$flag.",".(time()+86300*7).")";
    return $wpdb->query($sql);
}
/** }}} */
/*** {{{  ra_get_rpc 
 */ 
function ra_get_rpc($url=null)
{
    $rpc= new PHPRPC_Client();
    $rpc->setProxy(NULL);
    if(is_null($url))
        $rpc->useService(CLOUDAPI_URL);
    else
        $rpc->useService($url);

    return $rpc;
}
/** }}} */

/*** {{{  ra_get_keywords 
 * 取回某一文章的关键词;
 * 如果数据库里没有缓存,要通过cloudapi去取;
 */ 
function ra_get_keywords($content,$post_id)
{
    global $wpdb; 
    $keywords=ra_fetch_row("keywords_".$post_id);
    if(!empty($keywords))
        return $keywords;
    else{
        $url=CLOUDAPI_URL;
        $rpc= new PHPRPC_Client();
        $rpc->setProxy(NULL);
        $rpc->useService($url);
        $ret=$rpc->api_getkeywords(strip_tags($content),8,"n");
        $keywords=array();
        foreach($ret["data"] as $v){
            $keywords[]=$v[0];
        }
        ra_set_row("keywords_".$post_id,$keywords);
        return $keywords;
    }
}
/** }}} */
/*** {{{  ra_index_article 
 */ 
function ra_index_article($content,$url,$title) {

    $rpc=ra_get_rpc();
    $ret=$rpc->api_simple_index(RADB,$url."||##||".$title,strip_tags($content));
    if(is_a($ret, "PHPRPC_Error")){
        return false;
    }
    if($ret["code"]=="200"){
        ra_set_row("indexed_".md5($content),1);
        return true;
    }
    else
        return false;
}
/** }}} */

/*** {{{  ra_fetch_articles 
 */ 
function ra_fetch_articles($content)
{
    global $wpdb,$table_prefix;
    global $post;
    //如果本篇文章没有索引过,则索引本文;
    $md5=md5($content);
    $index=ra_fetch_row("indexed_".$md5);
    if(!$index){
        ra_index_article($content,get_permalink($post->ID),$post->post_title);
    }
    $related_articles=ra_fetch_row("related_".$post->ID);
    $keywords=ra_get_keywords($content,$post->ID);
    if(empty($related_articles)){
        //如果取不到相关文章;
        //则取关键词后再去查;
        $query=join(" ",$keywords)." ".$post->post_title;
        $rpc=ra_get_rpc();
        $ret=$rpc->api_simple_search(RADB,$query);
        if(is_a($ret, "PHPRPC_Error")){
            return $content;
        }
        if($ret["code"]=="200"){
            $relates=array();
            foreach($ret["data"] as $v){
                $line=explode("||##||",$v["data"]);
                $url=$line[0];$title=$line[1];
                if(!empty($title))
                    $relates[]=array("url"=>$url,"title"=>$title);
            }
            ra_set_row("related_".$post->ID,$relates);
        }
    }
    else{
        $relates=$related_articles;
    }
    $str="<div class='ra_content'>".$content."</div>";
    if(!empty($keywords)){
        "<div class='ra_keywords_nav'>文章关键词</div>".
            "<div class='ra_keywords'>";
        foreach($keywords as $v){
            $str = $str . "<B>".$v."</B>&nbsp;";
        }
        $str = $str . "</div>";
    }
    if(!empty($relates)){
        $str= $str. "<div class='ra_relates_nav'>相关文章</div><div class='ra_relates'>" ;
        reset($relates);
        foreach($relates as $v){
            if(!empty($v["title"]))
                if($v["url"]!=get_permalink($post->ID))
                    $str= $str. "<a  class='ra_related_link' href='".$v["url"]."' target='_blank' >".$v["title"]."</a><br/>" ;
        }
        $str=$str."</div>";
    }
    return $str;
}
/** }}} */

/*** {{{  ra_admin_tab 
*/ 
function ra_admin_tab()
{
         
}
/** }}} */

/*** {{{  ra_settings 
*/ 
function ra_settings()
{
    global $ra_apikey,$ra_db;
    if($_SERVER["REQUEST_METHOD"]=="GET"){
    echo '<div class="ra_setting">';
    echo '<h3>您目前的related articles API 设置</h3>';
    echo '<form Method="POST">';
    echo '<p>您的CLOUDAPI SECRET KEY:<input name="key" value="'.$ra_apikey.'"></p>';
    echo '<p>您的CLOUDAPI 资料库:<input name="db" value="'.$ra_db.'"></p>';
    echo '<p><button type="submit">修改</button></p>';
    echo '<p><a href="http://www.cloudapi.info/" target="_blank">现在就去cloudapi.info上申请一个搜索资料库</a></p>';
    echo '</div>';
    }
    else{
        update_option("ra.apikey",trim($_POST["key"]));
        update_option("ra.db",trim($_POST["db"]));
        $rpc=ra_get_rpc("http://cloudapi.info/search_api.py?code=".trim($_POST["key"]));
        $rpc->api_simple_init(trim($_POST["db"]));
        if(is_a($ret, "PHPRPC_Error")){
            echo '<div style="color:red;padding:50px;">修改意外失败!        <br>  <br> <a href="'.$_SERVER["REQUEST_URI"].'">返回</a></div>';
        }
        else
            echo '<div style="color:green;padding:50px;">修改成功!        <br>  <br> <a href="'.$_SERVER["REQUEST_URI"].'">返回</a></div>';

    }

}
/** }}} */
/*** {{{ ra_add_tab
 */
function ra_add_tab( $s ) {
    add_submenu_page( 'index.php', 'RelatedArticles', '相关文章', 1, __FILE__, 'Ra_settings' ); 
    return $s;
}
/** }}} */

add_action( 'admin_menu', 'ra_add_tab' );
add_filter("the_content","ra_fetch_articles",1000);

?>
