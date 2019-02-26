<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
declare (strict_types = 1);

namespace think\cache\driver;

use think\cache\Driver;
use think\Container;

/**
 * 文件类型缓存类
 * @author    liu21st <liu21st@gmail.com>
 */
class File extends Driver
{
    protected $options = [
        'expire'        => 0,
        'cache_subdir'  => true,
        'prefix'        => '',
        'path'          => '',
        'hash_type'     => 'md5',
        'data_compress' => false,
        'serialize'     => true,
        'tag_prefix'    => 'tag_',
    ];

    protected $expire;

    /**
     * 架构函数
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }

        $app = Container::pull('app');

        if (empty($this->options['path'])) {
            $this->options['path'] = $app->getRuntimePath() . 'cache' . DIRECTORY_SEPARATOR;
        } elseif (substr($this->options['path'], -1) != DIRECTORY_SEPARATOR) {
            $this->options['path'] .= DIRECTORY_SEPARATOR;
        }

        $this->init();
    }

    /**
     * 初始化检查
     * @access private
     * @return bool
     */
    private function init(): bool
    {
        // 创建项目缓存目录
        try {
            if (!is_dir($this->options['path']) && mkdir($this->options['path'], 0755, true)) {
                return true;
            }
        } catch (\Exception $e) {
            // 写入失败
        }

        return false;
    }

    /**
     * 取得变量的存储文件名
     * @access protected
     * @param  string $name 缓存变量名
     * @param  bool   $auto 是否自动创建目录
     * @return string
     */
    protected function getCacheKey(string $name, bool $auto = false): string
    {
        $name = hash($this->options['hash_type'], $name);

        if ($this->options['cache_subdir']) {
            // 使用子目录
            $name = substr($name, 0, 2) . DIRECTORY_SEPARATOR . substr($name, 2);
        }

        if ($this->options['prefix']) {
            $name = $this->options['prefix'] . DIRECTORY_SEPARATOR . $name;
        }

        $filename = $this->options['path'] . $name . '.php';
        $dir      = dirname($filename);

        if ($auto && !is_dir($dir)) {
            try {
                mkdir($dir, 0755, true);
            } catch (\Exception $e) {
                // 创建失败
            }
        }

        return $filename;
    }

    /**
     * 判断缓存是否存在
     * @access public
     * @param  string $name 缓存变量名
     * @return bool
     */
    public function has($name): bool
    {
        return false !== $this->get($name) ? true : false;
    }

    /**
     * 读取缓存
     * @access public
     * @param  string $name 缓存变量名
     * @param  mixed  $default 默认值
     * @return mixed
     */
    public function get($name, $default = false)
    {
        $this->readTimes++;

        $filename = $this->getCacheKey($name);

        if (!is_file($filename)) {
            return $default;
        }

        $content      = file_get_contents($filename);
        $this->expire = null;

        if (false !== $content) {
            $expire = (int) substr($content, 8, 12);
            if (0 != $expire && time() > filemtime($filename) + $expire) {
                //缓存过期删除缓存文件
                $this->unlink($filename);
                return $default;
            }

            $this->expire = $expire;
            $content      = substr($content, 32);

            if ($this->options['data_compress'] && function_exists('gzcompress')) {
                //启用数据压缩
                $content = gzuncompress($content);
            }
            return $this->unserialize($content);
        } else {
            return $default;
        }
    }

    /**
     * 写入缓存
     * @access public
     * @param  string        $name 缓存变量名
     * @param  mixed         $value  存储数据
     * @param  int|\DateTime $expire  有效时间 0为永久
     * @return bool
     */
    public function set($name, $value, $expire = null): bool
    {
        $this->writeTimes++;

        if (is_null($expire)) {
            $expire = $this->options['expire'];
        }

        $expire   = $this->getExpireTime($expire);
        $filename = $this->getCacheKey($name, true);

        if (!empty($this->tag) && !is_file($filename)) {
            $first = true;
        }

        $data = $this->serialize($value);

        if ($this->options['data_compress'] && function_exists('gzcompress')) {
            //数据压缩
            $data = gzcompress($data, 3);
        }

        $data   = "<?php\n//" . sprintf('%012d', $expire) . "\n exit();?>\n" . $data;
        $result = file_put_contents($filename, $data);

        if ($result) {
            isset($first) && $this->setTagItem($filename);
            clearstatcache();
            return true;
        }

        return false;
    }

    /**
     * 自增缓存（针对数值缓存）
     * @access public
     * @param  string    $name 缓存变量名
     * @param  int       $step 步长
     * @return false|int
     */
    public function inc(string $name, int $step = 1)
    {
        if ($this->has($name)) {
            $value  = $this->get($name) + $step;
            $expire = $this->expire;
        } else {
            $value  = $step;
            $expire = 0;
        }

        return $this->set($name, $value, $expire) ? $value : false;
    }

    /**
     * 自减缓存（针对数值缓存）
     * @access public
     * @param  string    $name 缓存变量名
     * @param  int       $step 步长
     * @return false|int
     */
    public function dec(string $name, int $step = 1)
    {
        if ($this->has($name)) {
            $value  = $this->get($name) - $step;
            $expire = $this->expire;
        } else {
            $value  = -$step;
            $expire = 0;
        }

        return $this->set($name, $value, $expire) ? $value : false;
    }

    /**
     * 删除缓存
     * @access public
     * @param  string $name 缓存变量名
     * @return bool
     */
    public function rm(string $name): bool
    {
        $this->writeTimes++;

        try {
            return $this->unlink($this->getCacheKey($name));
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 清除缓存
     * @access public
     * @return bool
     */
    public function clear(): bool
    {
        if (!empty($this->tag)) {
            // 指定标签清除
            foreach ($this->tag as $tag) {
                $this->clearTag($tag);
            }

            return true;
        }

        $this->writeTimes++;

        $files = (array) glob($this->options['path'] . ($this->options['prefix'] ? $this->options['prefix'] . DIRECTORY_SEPARATOR : '') . '*');

        foreach ($files as $path) {
            if (is_dir($path)) {
                $matches = glob($path . DIRECTORY_SEPARATOR . '*.php');
                if (is_array($matches)) {
                    array_map('unlink', $matches);
                }
                rmdir($path);
            } else {
                unlink($path);
            }
        }

        return true;
    }

    public function clearTag(string $tag)
    {
        $keys = $this->getTagItems($tag);

        foreach ($keys as $key) {
            $this->unlink($key);
        }

        $this->rm($this->getTagKey($tag));
    }

    /**
     * 判断文件是否存在后，删除
     * @access private
     * @param  string $path
     * @return bool
     */
    private function unlink(string $path): bool
    {
        return is_file($path) && unlink($path);
    }

}
