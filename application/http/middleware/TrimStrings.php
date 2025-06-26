<?php

namespace app\http\middleware;

class TrimStrings
{
    public function handle(\think\Request $request, \Closure $next)
    {
        // 排除字段配置（如密码等敏感字段不需要处理）
        $except = [
            'password',
            'password_confirmation'
        ];

        // 处理所有输入数据
        $input = $request->param();
        array_walk_recursive($input, function (&$value, $key) use ($except) {
            if (!in_array($key, $except) && is_string($value)) {
                $value = trim($value);
            }
        });
        // 通过反射修改请求参数
        try {
            $ref = new \ReflectionClass($request);
            $property = $ref->getProperty('param');
            $property->setAccessible(true);
            $property->setValue($request, $input);
        } catch (\ReflectionException $e) {
            // 异常处理
            throw new \think\exception\HttpResponseException(fail($e->getMessage()));
        }
        return $next($request);
    }
}
