<?php
/**
 * =================================================================================
 * LIBRERÍA DE FUNCIONES HTTP PARA SCRIPTCASE - of_http_lib
 * =================================================================================
 * Esta librería proporciona métodos para realizar peticiones HTTP (GET, POST, etc.)
 * utilizando cURL en PHP. Debe registrarse en Scriptcase como una Librería Interna.
 * 
 * Uso de importación en Scriptcase:
 *   sc_include_library("sys", "of_http_lib", "of_http_lib.php", true, true);
 * =================================================================================
 */

if (!class_exists('of_http_lib')) {
    class of_http_lib {
        /**
         * Realiza una petición POST enviando datos JSON.
         * 
         * @param string $url URL destino
         * @param array|string $data Datos a enviar (si es array se convertirá a JSON)
         * @param int $timeout Tiempo de espera en segundos
         * @param array $headers Cabeceras HTTP adicionales
         * @return array Array asociativo con 'success', 'status', 'error' y 'body'
         */
        public static function post_json($url, $data, $timeout = 30, $headers = []) {
            $ch = curl_init($url);
            if ($ch === false) {
                return [
                    'success' => false,
                    'status' => 500,
                    'error' => 'No se pudo inicializar cURL',
                    'body' => null
                ];
            }
            
            $payload = is_array($data) ? json_encode($data) : $data;
            
            $default_headers = [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload)
            ];
            
            $http_headers = array_merge($default_headers, $headers);
            
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $http_headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            
            // Omitir verificación SSL para ambientes locales o de desarrollo
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            $response = curl_exec($ch);
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            curl_close($ch);
            
            if ($response === false) {
                return [
                    'success' => false,
                    'status' => 500,
                    'error' => $error,
                    'body' => null
                ];
            }
            
            return [
                'success' => ($http_status >= 200 && $http_status < 300),
                'status' => $http_status,
                'error' => null,
                'body' => $response
            ];
        }

        /**
         * Realiza una petición GET.
         * 
         * @param string $url URL destino
         * @param int $timeout Tiempo de espera en segundos
         * @param array $headers Cabeceras HTTP adicionales
         * @return array Array asociativo con 'success', 'status', 'error' y 'body'
         */
        public static function get($url, $timeout = 30, $headers = []) {
            $ch = curl_init($url);
            if ($ch === false) {
                return [
                    'success' => false,
                    'status' => 500,
                    'error' => 'No se pudo inicializar cURL',
                    'body' => null
                ];
            }
            
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            $response = curl_exec($ch);
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            curl_close($ch);
            
            if ($response === false) {
                return [
                    'success' => false,
                    'status' => 500,
                    'error' => $error,
                    'body' => null
                ];
            }
            
            return [
                'success' => ($http_status >= 200 && $http_status < 300),
                'status' => $http_status,
                'error' => null,
                'body' => $response
            ];
        }
    }
}
