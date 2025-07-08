<?php

namespace app\library;

use think\Request;

class CustomRequest extends Request
{
    public function merge(array $data)
    {
        $this->withParam(array_merge($this->param(), $data));
        return $this;
    }

    public function replace(array $data)
    {
        $this->withParam($data);
        return $this;
    }

    protected function withParam(array $params)
    {
        $this->get  = $params;
        $this->post = $params;
        $this->param = $params;
    }
}
