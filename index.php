<?php
//Приём платежей от DIXIS
//https://api.evo73.ru/dixis/index.php?ACTION=payment&AMOUNT=100&PAY_ID=111&PAY_DATE=18.04.2019&ACCOUNT=268425
require ('/var/www/api.evo73.ru/sql/sql.php');

function log_($action,$account,$amount,$pay_id,$pay_date,$code,$message,$bm_code){
    //определение ip
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) { $ip = $_SERVER['HTTP_CLIENT_IP'];} 
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];} 
    else {$ip = $_SERVER['REMOTE_ADDR'];}

    if ($amount !== null){
        $amount	= $amount * 100;
    }	
    $db = connect_db();
    $q = $db -> prepare("INSERT INTO log_payments_dixis (action,account,amount,pay_id,pay_date,code,message,bm_code,ip) VALUES (?,?,?,?,?,?,?,?,?)");
    $q -> execute(array($action,$account,$amount,$pay_id,$pay_date,$code,$message,$bm_code,$ip));
}
          
function generate_xml($data){
    $xml = new XmlWriter();
    $xml->openMemory();
    $xml->startDocument('1.0', 'UTF-8');
    $xml->startElement('response');
    function write(XMLWriter $xml, $data){
        foreach($data as $key => $value){
            if(is_array($value)){
                $xml->startElement($key);
                write($xml, $value);
                $xml->endElement();
                continue;
            }
            $xml->writeElement($key, $value);
        }
    }
    write($xml, $data);
    $xml->endElement();
    header('Content-type: text/xml');
    echo $xml->outputMemory(true); 
}

if (isset($_GET['ACTION'])){
    if ($_GET['ACTION'] == 'payment'){
        if (isset($_GET['AMOUNT']) && !empty($_GET['AMOUNT']) && is_numeric($_GET['AMOUNT'])){
            // значение суммы получено, проверка на минимальное и максимальное значение
            $min = 1;
            $max = 15000;
            if ($_GET['AMOUNT'] >= $min && $_GET['AMOUNT'] <= $max){
                // проверка на максимальное и минимальное пройдена
                if (isset($_GET['PAY_ID']) && !empty($_GET['PAY_ID']) && is_numeric($_GET['PAY_ID'])){
                    // типа проверку PAY_ID прошли, хз какие у неё должны быть критерии
                    if (isset($_GET['PAY_DATE']) && !empty($_GET['PAY_DATE'])){
                        // такая же херня с PAY_DATE
                        // ещё раз проверям ЛС
                        if (isset($_GET['ACCOUNT']) && !empty($_GET['ACCOUNT']) && is_numeric($_GET['ACCOUNT'])){
                            $db_bill = new PDO("oci:dbname=//192.168.7.4/billing" . ';charset=UTF8', 'programmer', 'AD91Rdfd74');//подключение к биллингу
                            $db_bill->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                            $account_query = $db_bill-> query("select 
                                                                    a.stop_date, 
                                                                    a.domain_id, 
                                                                    decode(c.customer_type_id, 1, 0, decode(c.customer_type_id, 2, 1, decode(c.customer_type_id, 4, 1))) customer_type, 
                                                                    (select distinct 1 from bm11.services where type_id=50 and status<>'-20' and account_id=".$_GET['ACCOUNT'].") as tel
                                                                from bm11.accounts a, bm11.customers c
                                                                where a. account_id =".$_GET['ACCOUNT']." and a.customer_id=c.customer_id ");
                            $account_result = $account_query ->fetchAll(PDO::FETCH_ASSOC);
                            if ($account_result[0]['TEL'] == 1 || $account_result[0]['CUSTOMER_TYPE'] == 1 ){$tel = 1;}
                            else { $tel = 0;}
                            if (count($account_result) == 1){
                                if ($account_result[0]['STOP_DATE'] == null){
                                    if ($account_result[0]['DOMAIN_ID'] == 2){
                                        // проводим платёж
                                        $username = 'lkevocard_pay';
                                        $password = 'Gg53rRGja3t54ahr';
                                        $REG_DATE = date("d.m.Y_H:i:s");
                                        $txn_date = date(YmdHis);
                                        $PAY_URL='https://bm.evolife.su/lkevocard/cgi-bin/server.account?command=pay&txn_id='.$_GET['PAY_ID'].'&account='.$_GET['ACCOUNT'].'&sum='.$_GET['AMOUNT'].'&txn_date='.$txn_date;
                                        $PAY = curl_init();
                                        curl_setopt($PAY, CURLOPT_HEADER, false);
                                        curl_setopt($PAY, CURLOPT_USERAGENT, 'EVO LK BOT');
                                        curl_setopt($PAY, CURLOPT_SSL_VERIFYPEER, false);
                                        curl_setopt($PAY, CURLOPT_SSL_VERIFYHOST, false);
                                        curl_setopt($PAY, CURLOPT_URL,$PAY_URL);
                                        curl_setopt($PAY, CURLOPT_TIMEOUT, 30); 
                                        curl_setopt($PAY, CURLOPT_RETURNTRANSFER,1);
                                        curl_setopt($PAY, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
                                        curl_setopt($PAY, CURLOPT_USERPWD, "$username:$password");
                                        $PAY_result=curl_exec($PAY);
                                        curl_close ($PAY);
                                        $p = simplexml_load_string($PAY_result);
                                        if ($p->result == '0'){
                                            //успешно оплачено
                                            $code = 0;
                                            $message = 'Зачисление средств произведено успешно';
                                            $error_code = $p->result;

                                            $data = array("CODE" => $code,
                                                            "MESSAGE" => $message,
                                                            "TEL" => $tel
                                                        );
                                            log_($_GET['ACTION'],$_GET['ACCOUNT'],$_GET['AMOUNT'],$_GET['PAY_ID'],$_GET['PAY_DATE'],$code,$message,$error_code);	
                                            generate_xml($data);
                                        }else{
                                            // ошибка при оплате
                                            $error_code = $p->result;
                                            switch ($error_code){
                                                case 241:
                                                    $code = 4;
                                                    $message = 'Сумма слишком мала';
                                                    break;
                                                case 242:
                                                    $code = 4;
                                                    $message = 'Сумма слишком велика';
                                                    break;
                                                case 5:
                                                    $code = 3;
                                                    $message = 'Лицевой счёт не найден';
                                                    break;
                                                case 79:
                                                    $code = 3;
                                                    $message = 'Обслуживание данного лицевого счёта прекращено';
                                                default:
                                                    $code = -1;
                                                    $message = "Прочая ошибка";
                                            }

                                            $data = array("CODE" => $code,
                                                            "MESSAGE" => $message
                                                            );
                                            log_($_GET['ACTION'],$_GET['ACCOUNT'],$_GET['AMOUNT'],$_GET['PAY_ID'],$_GET['PAY_DATE'],$code,$message,$error_code);		
                                            generate_xml($data);	
                                        }
                                    }else{
                                        // лицевой счёт не из ульяновска
                                        $code = 3;
                                        $message = 'Данный лицевой счёт не зарегестрирован в г.Ульяновск';

                                        $data = array("CODE" => $code,
                                                        "MESSAGE" => $message
                                                        );
                                        log_($_GET['ACTION'],$_GET['ACCOUNT'],$_GET['AMOUNT'],$_GET['PAY_ID'],$_GET['PAY_DATE'],$code,$message,null);		
                                        generate_xml($data);
                                    }
                                }else{
                                    // лицевой счёт закрыт
                                    $code = 3;
                                    $message = 'Обслуживание данного лицевого счёта прекращено';

                                    $data = array("CODE" => $code,
                                                    "MESSAGE" => $message
                                                    );
                                    log_($_GET['ACTION'],$_GET['ACCOUNT'],$_GET['AMOUNT'],$_GET['PAY_ID'],$_GET['PAY_DATE'],$code,$message,null);		
                                    generate_xml($data);
                                }
                            }else{
                                // лицевой счёт не найден
                                $code = 3;
                                $message = 'Лицевой счёт не найден';

                                $data = array("CODE" => $code,
                                                "MESSAGE" => $message
                                                );
                                log_($_GET['ACTION'],$_GET['ACCOUNT'],$_GET['AMOUNT'],$_GET['PAY_ID'],$_GET['PAY_DATE'],$code,$message,null);		
                                generate_xml($data);
                            }
                        }else{
                            $code = 2;
                            $message = 'Пустое значение параметра "ACCOUNT"';

                            $data = array("CODE" => $code,
                                            "MESSAGE" => $message
                                            );
                            log_($_GET['ACTION'],$_GET['ACCOUNT'],$_GET['AMOUNT'],$_GET['PAY_ID'],$_GET['PAY_DATE'],$code,$message,null);	
                            generate_xml($data);
                        }
                    }else{
                        $code = 6;
                        $message = 'Неверное значение параметра "PAY_DATE"';

                        $data = array("CODE" => $code,
                                        "MESSAGE" => $message
                                        );
                        log_($_GET['ACTION'],$_GET['ACCOUNT'],$_GET['AMOUNT'],$_GET['PAY_ID'],$_GET['PAY_DATE'],$code,$message,null);	
                        generate_xml($data);
                    }
                }else{
                    $code = 5;
                    $message = 'Неверное значение параметра "PAY_ID"';

                    $data = array("CODE" => $code,
                                    "MESSAGE" => $message
                                    );
                    log_($_GET['ACTION'],$_GET['ACCOUNT'],$_GET['AMOUNT'],$_GET['PAY_ID'],$_GET['PAY_DATE'],$code,$message,null);			
                    generate_xml($data);
                }
            }else{
                $code = 4;
                $message = 'Неверная сумма платежа. Сумма платежа должна быть от '.$min.' до '.$max.' рублей';

                $data = array("CODE" => $code,
                                "MESSAGE" => $message
                                );
                log_($_GET['ACTION'],$_GET['ACCOUNT'],$_GET['AMOUNT'],$_GET['PAY_ID'],$_GET['PAY_DATE'],$code,$message,null);	
                generate_xml($data);
            }
        }else{
            $code = 4;
            $message = 'Неверное значение параметра "AMOUNT"';

            $data = array("CODE" => $code,
                            "MESSAGE" => $message
                            );
            log_($_GET['ACTION'],$_GET['ACCOUNT'],$_GET['AMOUNT'],$_GET['PAY_ID'],$_GET['PAY_DATE'],$code,$message,null);			
            generate_xml($data);
        }
    }else{
        $code = 2;
        $message = 'Неверное значение параметра "ACTION"';

        $data = array("CODE" => $code,
                        "MESSAGE" => $message
                        );
        log_($_GET['ACTION'],$_GET['ACCOUNT'],$_GET['AMOUNT'],$_GET['PAY_ID'],$_GET['PAY_DATE'],$code,$message,null);	
        generate_xml($data);		
    }
}else{
    $code = 2;
    $message = 'Неверный запрос';

    $data = array("CODE" => $code,
                    "MESSAGE" => $message
                );
    log_($_GET['ACTION'],$_GET['ACCOUNT'],$_GET['AMOUNT'],$_GET['PAY_ID'],$_GET['PAY_DATE'],$code,$message,null);
    generate_xml($data);
}
?>