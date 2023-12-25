<?php
namespace App\Components\Reviews;

use App\Helpers\Log;
use App\Helpers\Console;
use PDO;
use App\Components\Component;
use App\Exceptions\ApplicationError;
use App\Helpers\Formatter;
use App\Helpers\Http;

class StarterReviewsLoader extends Component
{
    protected $point = 0;
    protected $source;
    protected $api_key = null;
    protected $project_name = null;
    protected $reviews_count = 0;
    protected $source_channels = [];
    static $client_reviews = [];

    /**
     * Получает список ресурсов для сканирования из базы данных
     * @return array
     */
    public static function getSources()
    {
        $app = $GLOBALS["CRM_APP"];
        $pdo = $app->GetPDO();

        $point = Console::GetCoommandParam('point');
        $params = ['starterappreviews', 'reviews', 1];

        $sql = "SELECT * FROM `clients_sources` 
                WHERE `source_providers` = ? AND `source_data_type` = ? AND `source_active` = ?";

        // сработает в случае, если мы хотим парсить только одного клиента
        if($point) {
            $params[] = $point;
            $sql .= ' AND `point` = ? LIMIT 1';
        }

        $query = $pdo->prepare($sql);
        $query->execute($params);
        $result = $query->fetchAll(PDO::FETCH_ASSOC);

        return $result;
    }

    public function scan(array $client)
    {
        $this->point = $client['point'];
        $this->source = 'starterapp';
        $this->project_name = $client['client_id'];
        $this->api_key = $client['token'];

        $this->source_channels = array_diff(array_map(function($channel) {return trim($channel);}, explode(';', $client['source_channels'])), [""]);

        // получает список отзывов данного клиента для дальнейшего сравнения
        $this->getClientReviews();
        // парсим сервис
        $this->parseApiHost();
    }

    protected function getClientReviews()
    {
        $params = ['point' => $this->point, 'source' => $this->source];
        $sql = "SELECT `id`, `ext_id` FROM `feedback` WHERE `point` = :point AND `source` = :source";
        $query = $this->pdo->prepare($sql);
        $query->execute($params);
        Log::message(var_export(["sql" => $sql, "params" => $params], true), "starter");

        $result = [];
        while ($row = $query->fetch()) {
            $result[$row['ext_id']] = $row['id'];
        }

        self::$client_reviews = $result;
    }

    /**
     * @return void
     */
    protected function parseApiHost()
    {
        $offset = 0;
        $step = 0;
        $url = "https://feedback.api.starterapp.co/api/{$this->project_name}/public/feedbacks?limit=50&offset={$offset}";

        // подгружаем api отзывов в первый раз и получаем настройки для дальнейших выборок
        $response = $this->loadPage($url);

        while (count($response) && $step <= 1000) {
            foreach ($response as $review) {

                // если iD shop отсутствует в таблице поиска, пропускаем
                if (!empty($this->source_channels) && !in_array($review->shop->id, $this->source_channels)) continue;

                // если повторов ID отзыва больше 3, выход из цикла
                if ($this->reviews_count > 3) break;

                // подсчитывает количество повторов ID отзыва
                $this->AlreadyInDb($review->id);

                $comment = preg_replace('/\s+/', ' ', $review->comment);
                $answer = preg_replace('/\s+/', ' ', $review->answer);

                $tmp_review = [
                    'id' => $review->id,
                    'time' => date('Y-m-d H:i:s', strtotime($review->createdAt)),
                    'author' => $review->phone ? Formatter::NormalizePhone($review->phone, "hard") : '',
                    'text' => trim($comment),
                    'rating' => $review->rating,
                    'answer' => trim($answer)
                ];

                $this->addReviews($tmp_review);
            }

            $step++;
            $offset += 50;
            $url = "https://feedback.api.starterapp.co/api/{$this->project_name}/public/feedbacks?limit=50&offset={$offset}";
            $response = $this->loadPage($url);
        }
    }

    /**
     * @return int возвращает ID отзыва в базе данных
     */
    protected function addReviews($review, $comment_for = 0)
    {
        // пропускаем уже имеющийся отзыв в базе
        if (isset(self::$client_reviews[$review['id']])) return self::$client_reviews[$review['id']];

        $feedback_data = [
            'ext_id' => $review['id'],
            'comment_for' => $comment_for,
            'time' => $review['time'],
            'form_id' => 6,
            'source' => $this->source,
            'point' => $this->point,
        ];

        // не добавляем отзыв, если он уже есть в базе
        $feedback_id = $this->putFeedback($feedback_data);
        Log::message(var_export(["feedback_id" => $feedback_id], true), "starter");

        $fields = [
            'author' => $review['author'],
            'textReview' => $review['text'],
            'rating' => $review['rating'],
            'source' => 'Starter',
        ];

        $this->putFeedbackFields($feedback_id, $fields);
        return $feedback_id;
    }

    /**
     * Добавляет новую запись в таблицу feedback
     * @return int
     */
    protected function putFeedback($params)
    {
        $sql = "INSERT INTO `feedback` (`point`, `ext_id`, `comment_for`, `form_id`, `source`, `time`)
                VALUES (:point, :ext_id, :comment_for, :form_id, :source, :time)";
        Log::message(var_export(["sql" => $sql, "params" => $params], true), "starter");

        $query = $this->pdo->prepare($sql);
        $query->execute($params);

        return $this->pdo->lastInsertId();
    }

    /**
     * Добавляет новые записи в таблицу feedback_fields
     * @return int
     */
    protected function putFeedbackFields($feedback_id, $data)
    {
        $values = [];
        $execute_params = [];

        foreach ($data as $key => $value) {
            $values[] = "(?, ?, ?, ?)";
            $execute_params = array_merge($execute_params, [
                $this->point,
                $feedback_id,
                $key,
                $value,
            ]);
        }

        $values = implode(',', $values);
        $sql = "INSERT INTO `feedback_fields` (`point`, `feedback_id`, `field`, `value`) VALUES {$values}";
        Log::message(var_export(["sql" => $sql, "params" => $execute_params], true), "starter");

        $query = $this->pdo->prepare($sql);
        $query->execute($execute_params);

        return $this->pdo->lastInsertId();
    }

    protected function AlreadyInDb($review_ID) {
        $scanned_reviews = $this->pdo->prepare("SELECT * FROM `feedback` WHERE `ext_id`= ? AND `source`= ? AND `point`= ?");
        $scanned_reviews->execute([$review_ID, $this->source, $this->point]);
        $review = $scanned_reviews->fetch();

        // если запись найдена, увеличивает счетчик повтора
        if ($review['ext_id']) $this->reviews_count++;
    }

    /**
     * Получает массив отзывов с сайта starterapp.co
     * @return array
     */
    protected function loadPage($url)
    {
        $response = (new Http())->GetRequest($url, 'get', [], ["Authorization: {$this->api_key}"]);
        $response = json_decode($response);
        $response = $response->objects;

        return $response;
    }
}