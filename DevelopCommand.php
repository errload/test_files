<?php

namespace App\Commands;

use App\Components\Sendproviders\ImobisProvider;
use App\Components\SMSMng;
use \App\Models\V1\Reserves\TableDesign;
use \App\Models\V1\ShortUrl;
use \App\Helpers\System;
use \App\Models\V1\CabinetUserRight;
use \App\Models\V1\CabinetUser;

class DevelopCommand extends Command {

    public function run() {


        try {

            // log файл для чтения
            $file = CRM_PATH . '/var/tmp/loyalty.log';
            $file = file_get_contents($file);

            $results = [];

            // путь к json файлу сохранения
            $path = CRM_PATH . '/var/tmp';
            if (!is_dir($path)) mkdir($path);

            // разделение на массивы
            $file = preg_replace('/\s+/', '', $file);
            $file = explode(':array', $file);

            // перебор поинтов
            foreach ($file as $item) {
                $pos = stripos($item, "'point'=>4441000");
                if (!$pos) continue;

                // удаление лишних символов
                $item = str_replace('\'', '', $item);
                $item = str_replace('"', '', $item);
                $item = str_replace('=>', ':', $item);
                $exp = explode(',', $item);

                $email = null;
                $phone = null;

                // перебор полученных строк на поиск email и phone
                foreach ($exp as $point) {
                    // если строка начинается с mail, email или phone
                    if (stripos($point, 'mail') === 0) $email = substr($point, 5);
                    if (stripos($point, 'email') === 0) $email = substr($point, 6);
                    if (stripos($point, 'phone') === 0) $phone = substr($point, 6);

                    // у телефонов есть записи с лишними символами (в json формате)
                    if ($phone) {
                        $phone = str_replace(':', '', $phone);
                        $phone = str_replace('[', '', $phone);
                        $phone = str_replace(']', '', $phone);
                    }
                }

                // запись в результирующий массив
                $result = ['email' => $email, 'phone' => $phone];
                $result = json_encode($result);
                if (!in_array($result, $results)) $results[] = $result;
            }

            // запись в файл
            file_put_contents($path . '/log.json', $results);

        } catch (\Throwable $e) {
            var_dump($e->GetMessage());
        }

    }

}
