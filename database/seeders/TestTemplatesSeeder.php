<?php

namespace Database\Seeders;

use App\Models\Block;
use Illuminate\Database\Seeder;

class TestTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        //php artisan db:seed --class=TestTemplatesSeeder
        // Массив с данными: настройки, заголовок и описание
        $templatesData = [
            [
                'title' => 'Текст с изображением справа',
                'description' => 'Блок с текстом и галереей',
                'settings' => '{"key": "type", "type": "layout", "label": "Текст + Изображение", "params": null, "public": false, "allowed": ["single", "row", "col"], "default": "row", "children": [{"key": "icon", "type": "icon", "label": "Иконка", "params": null, "public": false, "allowed": null, "default": "i-lucide-layout-panel-left", "required": false, "description": null}, {"key": "first-block-cols", "type": "int", "label": "Ширина первого блока, колонок", "params": null, "public": false, "allowed": null, "default": 12, "required": false, "description": null}, {"key": "first-block-position", "type": "select", "label": "Позиция первого блока", "params": null, "public": false, "allowed": ["flex-start", "center", "flex-end"], "default": "center", "required": false, "description": null}, {"key": "background-color", "type": "color", "label": "Цвет фона", "params": null, "public": false, "allowed": ["bg-default", "bg-definition", "bg-theorem", "bg-statement", "bg-lemma", "bg-proof", "bg-conclusion", "bg-solution", "bg-example", "bg-task", "bg-exercise", "bg-illustration", "bg-note", "bg-warning-block", "bg-error-block", "bg-important", "bg-quote", "bg-history", "bg-remark", "bg-reference", "bg-white", "bg-inherit"], "default": "bg-default", "required": false, "description": null}, {"key": "background-image", "type": "image", "label": "Фоновое изображение", "params": {"count": 1, "purpose": "background-image", "display-images": true}, "public": false, "allowed": null, "default": [], "required": false, "description": null}, {"key": "background-size", "type": "select", "label": "Размер фона", "params": null, "public": false, "allowed": ["auto", "cover", "contain", "50%", "100%"], "default": "auto", "required": false, "description": null}, {"key": "background-position", "type": "select", "label": "Положение фона", "params": null, "public": false, "allowed": ["top", "left", "center", "top left", "top right", "bottom left", "bottom right", "center center"], "default": "top", "required": false, "description": null}, {"key": "padding", "type": "array", "label": "Padding", "params": null, "public": false, "allowed": null, "default": null, "children": [{"key": "left", "type": "int", "label": "Слева", "params": null, "public": false, "allowed": null, "default": 0, "required": false, "description": null}, {"key": "right", "type": "int", "label": "Справа", "params": null, "public": false, "allowed": null, "default": 0, "required": false, "description": null}, {"key": "top", "type": "int", "label": "Сверху", "params": null, "public": false, "allowed": null, "default": 0, "required": false, "description": null}, {"key": "bottom", "type": "int", "label": "Снизу", "params": null, "public": false, "allowed": null, "default": 0, "required": false, "description": null}], "required": false, "description": null}, {"key": "margin", "type": "array", "label": "Margin", "params": null, "public": false, "allowed": null, "default": null, "children": [{"key": "left", "type": "int", "label": "Слева", "params": null, "public": false, "allowed": null, "default": 0, "required": false, "description": null}, {"key": "right", "type": "int", "label": "Справа", "params": null, "public": false, "allowed": null, "default": 0, "required": false, "description": null}, {"key": "top", "type": "int", "label": "Сверху", "params": null, "public": false, "allowed": null, "default": 0, "required": false, "description": null}, {"key": "bottom", "type": "int", "label": "Снизу", "params": null, "public": false, "allowed": null, "default": 0, "required": false, "description": null}], "required": false, "description": null}, {"key": "border", "type": "array", "label": "Границы", "params": null, "public": false, "allowed": null, "default": null, "children": [{"key": "color", "type": "color", "label": "Цвет", "params": null, "public": false, "allowed": ["border-default", "border-definition", "border-theorem", "border-statement", "border-lemma", "border-proof", "border-conclusion", "border-solution", "border-example", "border-task", "border-exercise", "border-illustration", "border-note", "border-warning-block", "border-error-block", "border-important", "border-quote", "border-history", "border-remark", "border-reference", "border-white", "border-inherit"], "default": "border-default", "required": false, "description": null}, {"key": "left", "type": "int", "label": "Слева", "params": null, "public": false, "allowed": null, "default": 0, "required": false, "description": null}, {"key": "right", "type": "int", "label": "Справа", "params": null, "public": false, "allowed": null, "default": 0, "required": false, "description": null}, {"key": "top", "type": "int", "label": "Сверху", "params": null, "public": false, "allowed": null, "default": 0, "required": false, "description": null}, {"key": "bottom", "type": "int", "label": "Снизу", "params": null, "public": false, "allowed": null, "default": 0, "required": false, "description": null}, {"key": "radius", "type": "array", "label": "Радиус", "params": null, "public": false, "allowed": null, "default": null, "children": [{"key": "top-left", "type": "int", "label": "Левый верхний", "params": null, "public": false, "allowed": null, "default": 0, "required": false, "description": null}, {"key": "top-right", "type": "int", "label": "Правый верхний", "params": null, "public": false, "allowed": null, "default": 0, "required": false, "description": null}, {"key": "bottom-right", "type": "int", "label": "Нижний правый", "params": null, "public": false, "allowed": null, "default": 0, "required": false, "description": null}, {"key": "bottom-left", "type": "int", "label": "Нижний левый", "params": null, "public": false, "allowed": null, "default": 0, "required": false, "description": null}], "required": false, "description": null}], "required": false, "description": null}, {"key": "content-blocks", "type": "array", "label": "Блоки", "params": null, "public": false, "allowed": null, "default": [], "children": [{"key": "block1", "type": "content-type", "label": "Блок1", "params": null, "public": false, "allowed": ["text", "gallery", "htmltext"], "default": "text", "children": [{"key": "gallery", "type": "array", "label": "Изображения", "params": null, "public": false, "allowed": null, "default": [], "children": [{"key": "gallery1", "type": "image", "label": "Галерея", "params": {"count": "parent.count", "purpose": "gallery1", "display-images": false}, "public": false, "allowed": null, "default": [{"url": "/storage/models/file_sets/2a/38/88/iLWne2XVE9ZTJ6milybrv48CwVz5voN0VuMesOpJ.jpg", "title": null, "source": "stored", "thumbnail": "/storage/models/file_sets/2a/38/88/thumb_iLWne2XVE9ZTJ6milybrv48CwVz5voN0VuMesOpJ.jpg"}], "children": []}]}]}, {"key": "block2", "type": "content-type", "label": "Блок2", "params": null, "public": false, "allowed": ["text", "gallery", "htmltext"], "default": "gallery", "children": [{"key": "gallery", "type": "array", "label": "Изображения", "params": null, "public": false, "allowed": null, "default": [], "children": [{"key": "gallery2", "type": "image", "label": "Галерея", "params": {"count": "parent.count", "purpose": "gallery2", "display-images": false}, "public": true, "allowed": null, "default": [{"url": "/storage/models/file_sets/b6/d7/22/5qeJJQg4a25UuaQYVoVbGQd217kjFNnAqf6OjqSw.jpg", "title": null, "source": "stored", "thumbnail": "/storage/models/file_sets/b6/d7/22/thumb_5qeJJQg4a25UuaQYVoVbGQd217kjFNnAqf6OjqSw.jpg"}], "children": []}]}]}]}]}'
            ],
            [
                'title' => 'Простой текст',
                'description' => 'Блок с текстом',
                'settings' => '{"key": "type", "type": "layout", "label": "Текст + Изображение", "params": null, "public": false, "allowed": ["single", "row", "col"], "default": "single", "children": [{"key": "content-blocks", "type": "array", "label": "Блоки", "params": null, "public": false, "allowed": null, "default": [], "children": [{"key": "block1", "type": "content-type", "label": "Блок1", "params": null, "public": false, "allowed": ["text", "gallery", "htmltext"], "default": "text", "children": [{"key": "gallery", "type": "array", "label": "Изображения", "params": null, "public": false, "allowed": null, "default": [], "children": [{"key": "gallery1", "type": "image", "label": "Галерея", "params": {"count": "parent.count", "purpose": "gallery1", "display-images": false}, "public": false, "allowed": null, "default": [], "children": []}]}]}, {"key": "block2", "type": "content-type", "label": "Блок2", "params": null, "public": false, "allowed": ["text", "gallery", "htmltext"], "default": "gallery", "children": [{"key": "gallery", "type": "array", "label": "Изображения", "params": null, "public": false, "allowed": null, "default": [], "children": [{"key": "gallery2", "type": "image", "label": "Галерея", "params": {"count": "parent.count", "purpose": "gallery2", "display-images": false}, "public": false, "allowed": null, "default": [], "children": []}]}]}]}]}'
            ],
            [
                'title' => 'Текст + Изображение сверху',
                'description' => 'Блок с текстом и галереей сверху',
                'settings' => '{"key": "type", "type": "layout", "label": "Текст + Изображение", "params": null, "public": false, "allowed": ["single", "row", "col"], "default": "col", "children": [{"key": "content-blocks", "type": "array", "label": "Блоки", "params": null, "public": false, "allowed": null, "default": [], "children": [{"key": "block1", "type": "content-type", "label": "Блок1", "params": null, "public": false, "allowed": ["text", "gallery", "htmltext"], "default": "gallery", "children": [{"key": "gallery", "type": "array", "label": "Изображения", "params": null, "public": false, "allowed": null, "default": [], "children": [{"key": "gallery1", "type": "image", "label": "Галерея", "params": {"count": "parent.count", "purpose": "gallery1", "display-images": false}, "public": true, "allowed": null, "default": [{"url": "/storage/models/file_sets/2a/38/88/iLWne2XVE9ZTJ6milybrv48CwVz5voN0VuMesOpJ.jpg", "title": null, "source": "stored", "thumbnail": "/storage/models/file_sets/2a/38/88/thumb_iLWne2XVE9ZTJ6milybrv48CwVz5voN0VuMesOpJ.jpg"}], "children": []}]}]}, {"key": "block2", "type": "content-type", "label": "Блок2", "params": null, "public": false, "allowed": ["text", "gallery", "htmltext"], "default": "htmltext", "children": [{"key": "gallery", "type": "array", "label": "Изображения", "params": null, "public": false, "allowed": null, "default": [], "children": [{"key": "gallery2", "type": "image", "label": "Галерея", "params": {"count": "parent.count", "purpose": "gallery2", "display-images": false}, "public": false, "allowed": null, "default": [], "children": []}]}]}]}]}'
            ]
        ];

        foreach ($templatesData as $data) {
            // Декодируем JSON в массив
            $settings = json_decode($data['settings'], true);

            // Рекурсивно заменяем изображения
            $settings = $this->replaceImages($settings);

            // Создаем блок
            $block = Block::create([
                'code' => 'text_image',
                'user_id' => 1,
                'settings' => $settings,
                'published' => true,
                'is_test' => true,
            ]);

            // Создаем перевод для блока
            $block->translations()->create([
                'locale' => 'ru',
                'title' => $data['title'],
                'description' => $data['description'],
            ]);
        }
    }

    /**
     * Рекурсивно ищет ссылки на картинки и меняет их на случайные горизонтальные пейзажи/животных
     */
    private function replaceImages(array $array): array
    {
        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                $value = $this->replaceImages($value);
            } elseif (is_string($value)) {
                if (($key === 'url' || $key === 'thumbnail') && str_contains($value, '/storage/')) {
                    $randomId = rand(1, 1000);
                    $value = "https://picsum.photos/1200/600?random={$randomId}";
                }
            }
        }

        return $array;
    }
}
