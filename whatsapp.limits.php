<? if (!defined('CRM_SYSTEM') || CRM_SYSTEM !== true) die(); ?>
<? $this->ShowLayout('header', $pageData); ?>

<style>
    .d-none { display: none; }
    thead, tbody { font-family: Montserrat; }

    thead tr th {
        font-weight: bold;
        font-size: 12px !important;
        padding: 0.5rem 2rem !important;
        text-align: center;
    }

    thead tr th:first-child { text-align: left; }
    tbody tr th, tbody tr td { font-size: 12px !important; }
    tbody tr th { font-size: 12px !important; }
    tbody tr td { text-align: center; }
    .tbody__row { font-weight: bold; }
    thead tr { background: #F3F2F7; }
    .panel-body { padding: 20px 0 20px; }
    .phone__active { background: #28C76F; }
    .phone__deactive { background: #EF3A45; }

    .phone__active, .phone__deactive {
        color: #fff;
        padding: 3px 8px;
        border-radius: 4px;
    }

    .watsapp__select {
        padding: 3px;
        border-radius: 4px;
        border: 1px solid #D8D6DE;
        outline: none;
    }

    .btn__wrapper {
        margin-top: 10px;
        padding: 0 20px;
        text-align: right;
    }
</style>


<!-- Библиотека генерации QR-кодов: -->
<!--<script src="https://cosmic.mearie.org/2011/01/qrjs/qr.js"></script>  -->
<script src="<?= \App\Helpers\System::GetTemplateURL() ?>js/whatsapp_qr.js"></script>

<!-- Телефоны, генерация QR-кодов: -->
<div class="panel panel-flat">
    <div class="panel-body no-scrolling">

        <? if (empty($pageData['data']["phones"])):?>
            <div class="stwrpatext" style="height: 100px;<?= !empty($pageData['data']["token"]) ? 'position: unset;' : '' ?>">
            <div class="sttext">Нет данных</div></div>
        <? else: ?>

            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Телефон</th>
                        <th scope="col">Состояние</th>
                        <th scope="col">Дата отвязки</th>
                        <th scope="col">Обратная связь</th>
                        <th scope="col">Рассылки</th>
                        <th scope="col">По сегменту</th>
                        <th scope="col">После визита</th>
                        <th scope="col">Именинникам</th>
                        <th scope="col">Уведомления</th>
                    </tr>
                </thead>

                <tbody>
                    <? foreach ($pageData['data']['phones'] as $phoneId => $phoneData): ?>
                        <form id="form_<?= $phoneId ?>" action="https://crm/<?= $this->point ?>/whatsapp.limits" method="POST">
                            <input type="hidden" name="phone_id" value="<?= $phoneId ?>">

                            <tr>
                                <th class="tbody__row"><?= $phoneData['phone'] ?></th>
                                <td>
                                    <div class="<?= $phoneData['active'] == 'Привязан' ? 'phone__active' : 'phone__deactive' ?>">
                                        <?= $phoneData['active'] ?>
                                    </div>
                                </td>
                                <td><?= $phoneData['notify_date'] ?></td>

                                <? foreach (['feedback_limit', 'sender_limit', 'segment_limit', 'aftervisit_limit', 'birthday_limit'] as $limitDesc): ?>
                                    <td>
                                        <select class="watsapp__select" name="<?= $limitDesc ?>" onChange="limitsUpdate(<?= $phoneId ?>)">
                                            <? foreach ($pageData['data']['selects']['default'] as $limit): ?>
                                                <option value="<?= $limit ?>"
                                                    <? if ($phoneData[$limitDesc] == $limit) : ?>
                                                        selected="selected"
                                                    <? endif; ?>>
                                                    <?= $limit ?>
                                                </option>
                                            <? endforeach; ?>
                                        </select>
                                    </td>
                                <? endforeach; ?>

                                <td>
                                    <select class="watsapp__select" name="notify_limit" onChange="limitsUpdate(<?= $phoneId ?>)">
                                        <? foreach ($pageData['data']['selects']['notify'] as $limit => $text): ?>
                                            <option value="<?= $limit ?>"
                                                <? if ($phoneData['notify_limit'] == $limit) : ?>
                                                    selected="selected"
                                                <? endif; ?>>
                                                <?=$text ?>
                                            </option>
                                        <? endforeach; ?>
                                    </select>
                                </td>
                            </tr>

                        </form>
                    <? endforeach; ?>
                </tbody>
            </table>

            <div class="btn__wrapper">
                <button type="submit" id="saveBtn_" class="btn btn-success d-none saveBtn_" data-i18n="Сохранить">
                    Сохранить
                </button>
            </div>

        <? endif; ?>
    </div>
</div>

<script>
    function limitsUpdate(phoneId) {
        document.addEventListener('DOMContentLoaded', () => {
            const isAdmin = '<?= $pageData['data']['is_admin'] ?>'
            if(!isAdmin) return
        })

        let btnId = `saveBtn_`
        $(`#${btnId}`).removeClass('d-none')

        document.querySelector('#saveBtn_').addEventListener('click', () => {
            document.querySelector(`#form_${phoneId}`).submit();
        })
    }
</script>

<? $this->ShowLayout("footer", $pageData); ?>
