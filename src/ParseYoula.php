<?php
/**
 * Created by PhpStorm.
 * User: JesusHatesU
 * Date: 22.05.2019
 * Time: 13:51
 */

class ParseYoula
{
    private $__value;
    private $__youlaAdsArr;

    function setValue(){
        //получаем значение, которое требуется найти
        $this->__value = (isset($_POST['request']) && $_POST['request'] != '')
            ? trim($_POST['request'])
            : '';
    }

    function getValue(){
        return $this->__value;
    }

    function parse(){
        $this->setValue();

        //мини-фильтрация
        $value = customTrim($this->__value);

        $_value = $this->getValue();
        $html = getHead().getInputForm($_value);

        //считывает все "старые" объявления, которые мы уже находили
        fclose(fopen('youlaParsingAds1.txt', 'a+t'));
        $file = fopen("youlaParsingAds1.txt", "a+t") or die();
        flock($file, LOCK_SH);
        $str = fread($file, 1000000);
        flock($file, LOCK_UN);
        fclose($file);

        //преобразуем в массив значений
        //выглядит следующим образом:
        //[
        //    'значение0' => [
        //                       0 => [
        //                              __location => '',
        //                              __cost => '',
        //                              ...
        //                              __id => ''
        //                             ],
        //                       1 => [...],
        //                       ...
        //                       M => [...]
        //                    ],
        //    'значение1' => [...],
        //    ...
        //    'значениеN' => [...]
        //]
        $this->__youlaAdsArr = json_decode($str, true);

        //если значение получено, то парсим и перезаписываем в файл
        if ($value != '') {
            $html  .= parse_youla($value, $this->__youlaAdsArr);
            $file = fopen("youlaParsingAds1.txt", "w+t") or die();
            flock($file, LOCK_EX);
            fwrite($file, json_encode($this->__youlaAdsArr));
            flock($file, LOCK_UN);
            fclose($file);

            $_POST['request'] = '';
        }

        //добавляем подвальчик
        $html .= getFooter();

        return $html;
    }

    //подаётся номер страницы и значение, проверяет присутствие объявлений на этой странице
    function isDomainAvailable($page, $val)
    {
        $response = file_get_contents('https://youla.ru/moskva?city=576d0612d53f3d80945f8b5d&page='.$page.'&q='.$val.'&serpId=3757f796e40529');

        return (stristr($response, "Увы, мы&nbsp;не&nbsp;нашли&nbsp;то, что вы&nbsp;искали."))
            ? false
            : $response;
    }

    //стили и шапка
    function getHead(){
        return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>Parse Youla</title>
<style>
	* {padding:0px; margin:0px; border-collapse:collapse; font: 20px Italic;}
	.main { position: absolute; width: 800px; left: 300px;}
	.inputForm { margin: 5px 5px; padding: 5px;  border: 1px #999 solid; background-color: #ccc; width: 780px;}
	.inputForm input{ margin: 10px 0; height: 40px; width: 765px; padding: 5px; border: 1px #999 solid;}
	.ad { position: relative; width: 780px; margin: 30px auto; padding: 5px; height: 220px; border: 1px #999 solid; background-color: #ccc;}
	.ad img{clear:both;  width: 200px; height: 215px;}
	.description{ float: right; width: 550px;}
</style>
</head>
<body>
<div class="main">';
    }

    //форма для ввода запроса, подаётся значение для поиска, чтобы не очищалось после нажатия "Найти"
    function getInputForm($__val){
        $value = (($__val != '')
                ? 'value="'
                : '').$__val.'"';
        return '<div class="inputForm">
        <span>Введите запрос:</span>
        <br />
        <form action="" method="post">
        <input type="text" name="request" placeholder="Что вы хотите найти?" '.$value.'>
        <input type="submit" value="Найти">
        </form>
    </div>';
    }

    //выводит блок одного объявления с информацией (ниже подробнее описано)
    function getAd($val){
        return '<div class="ad">
    	<img src="'.$val['__image'].'" alt="'.$val['__shortDescription'].'" />
        <div class="description">
            <a href="'.$val['__link'].'" title="'.$val['__shortDescription'].'" >'.$val['__shortDescription'].'</a><br />
            <span>Location: '.$val['__location'].'</span><br />
            <span>Price: '.$val['__cost'].'</span><br />
            <span>Date: '.$val['__date'].'</span>
        </div>
    </div>';
    }

    //подвальчик
    function getFooter(){
        return '</div>
</body>
</html>';
    }

    //производит парсинг всех объявлений на Юле, по заданному значению и заданному массиву
    //$__value - значение, по которому ищутся объявления
    //$__youlaAdsArr - массив, который формируется из файла с предыдущими результатами поиска по ВСЕМ значениям (может быть пустым)
    //в него будут добавляться новые объявления, которые были добавлены после последнего парсинга (при этом они будут выводиться сверху)
    //старые объявления (которые были найдены при предыдущих парсингах) остаются нетронутыми
    //$return - HTML-код в виде блоков, в которых есть превью-картинка товара, краткое описание, стоимость, дата публикации,
    //месторасположение (город) и ссылка на само объявление, при этом оригинальное объявление могло быть удалено, но ссылка
    //останется, чтобы посмотреть в БД всех объявлений на сторонних ресурсах
    function parse_youla($__value, &$__youlaAdsArr)
    {
        $count = 1;                                         //счётчик по страницам
        $arr = [];                                          //массив для парсинга
        $result = (is_array($__youlaAdsArr[$__value]))      //если вообще есть старые объявления, то добавить надпись
            ?'Старые объявления, которые вы уже видели:'
            :'';
        $isSomeNewAds = 0;                                  //флаг для новых объявлений

        //регулярка, которая парсит одну страницу
        $basicPattern = '/(
            <li \s* 
            class=\"product_item\" \s* 
            data-id=\"(?<youla_id>.+?)\" .*? 
            <a \s* href=\"(?<youla_ad_link>.*?)\" \s*
            title=\"(?<youla_title>.*?)\" .*?
            <span \s* class=\"gallery_counter__value\">(?<count_images>\d+) < .*?
            <img \s* src=\"(?<image_link> .*? )\" .*?
            <\/div [a-z \s = \" _ < >]* > \s* (?<location> [а-я А-Я ё Ё]+)  .*?
            <div \s* class=\"product_item__description \s .*? \"> \s*
            (?<cost> [Бесплатно \d \s]+) .*?
            <span \s* class=\"(?<currency> \w+)\" .*?
            <span \s* class=\"hidden-xs\">  (?<time> .*?) <\/span> \s*
            <span \s* class=\"visible-xs\"> \s* (?<date> \d{1,2} \. \d{1,2} \. \d{2,4} ) <\/span>
            )/usix';

        //цикл по страницам поиска
        while (($html = isDomainAvailable($count, $__value)) !== false) {

            //парсинг
            preg_match_all($basicPattern, $html, $arr);

            //ликвидируем лишние карманы, необязательно, но удобно для дебагинга через принты
            $countOfArr = count($arr);
            for ($j = 0; $j < $countOfArr; $j++) {
                if (isset($arr[$j])) {
                    unset($arr[$j]);
                }
            }

            //заполняем массив объектов и накапливаем результат в переменную
            foreach ($arr['youla_id'] as $key => $val) {
                //если не существовало записей по этому значению, то добавлять без вопросов
                //или если не было записей именно по этому результату
                if (is_array($__youlaAdsArr[$__value]) === false || ($index = in_array_youla($val, $__youlaAdsArr[$__value])) === false){

                    //обрезаем лишние символы, которые добавляет сама Юла
                    $arr['youla_title'] [$key] = mb_substr($arr['youla_title'] [$key], 0, -11);

                    //добавляем в "неполную" ссылку ообъявления протокол и домены
                    $arr['youla_ad_link'][$key] = 'https://youla.ru' . $arr['youla_ad_link'][$key];

                    //обрезаем пробелы внутри цены
                    $arr['cost'][$key] = preg_replace('/[^\d]+/', '', $arr['cost'][$key]);

                    //заполняем массив объявления
                    $youlaAd ['__link'] = $arr['youla_ad_link'][$key];
                    $youlaAd ['__location'] = $arr['location'][$key];
                    $youlaAd ['__cost'] = $arr['cost'][$key] . ' ' . $arr['currency'][$key];
                    $youlaAd ['__date'] = $arr['date'][$key];
                    $youlaAd ['__shortDescription'] = $arr['youla_title'][$key];
                    $youlaAd ['__image'] = $arr['image_link'][$key];
                    $youlaAd ['__id'] = $arr['youla_id'][$key];

                    //добавляем объявление в общий массив объявлений по данному значению
                    $__youlaAdsArr[$__value] [] = $youlaAd;

                    //т.к. это новое "для нас" объявление, то выводим его сверху, а флагу говорим, что были новые объявления
                    $isSomeNewAds = 1;
                    $result = getAd($youlaAd).$result;
                }
                else{
                    //если такое объявление уже добавлялось, то получаем его через индекс, который получили при поиске
                    //и выводим его в конце
                    $result .= getAd($__youlaAdsArr[$__value][$index]);
                }
            }

            //некст степ
            $arr= [];
            $count++;
        }

        //если новые добавлялись, то флаг нам об этом скажет, следовательно добавит соответствующую надпись
        $result = 'Найдено всего - '.count($__youlaAdsArr[$__value]).' вариантов:<br />'.(($isSomeNewAds)
                ? 'Добавлены новые объявления, которые вы раньше не видели:'
                :'').$result;

        return $result;
    }

    //кастомная мини-фильтрация входного значения, чтобы при разном регистре и дополнительных пробелах не создавалось
    //много лишних записей с одинаковыми объявлениями
    function customTrim($__str)
    {
        $__str = mb_strtolower($__str);
        $__str = preg_replace('/([\S]+)[\s]+/usi', '$1_', $__str);
        return $__str;
    }

    //ищет айди объявления в массиве объявлений
    //$value - айди которое ищем
    //$youlaArr - массив ОБЪЯВЛЕНИЙ, в котором ищем совпадения, не массив значений, а именно объявлений
    //return - index нужного объявления или FALSE, если не найдено
    function in_array_youla($value, array $youlaArr){

        foreach ($youlaArr as $key => $val){
            if ($val ['__id'] == $value)
                return $key;
        }

        return false;
    }

}