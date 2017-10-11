<?php namespace Common\Transformers;

use Common\Models\Text;
use Common\Models\MessageRevision;

class TextTransformer extends BaseTransformer
{

    /**
     * @param Text|MessageRevision $text
     * @return array
     */
    public function transform($text)
    {
        return [
            'text'    => $text->text,
            'buttons' => (array)$this->transformInclude($text->buttons, new MessageTransformer())
        ];
    }

}