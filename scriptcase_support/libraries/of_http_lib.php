<?php
/**
 * =================================================================================
 * LIBRERÍA DE FUNCIONES HTTP PARA SCRIPTCASE - of_http_lib.php
 * =================================================================================
 * Archivo único de funciones PHP puras (sin clases ni variables globales).
 * Diseñado para ser cargado e invocado como Librería Interna en Scriptcase.
 * 
 * Uso de importación en Scriptcase:
 *   sc_include_library("sys", "of_http_lib", "of_http_lib.php", true, true);
 * =================================================================================
 */

/**
 * Consulta la tabla tb_env en la base de datos de Scriptcase (único registro)
 * para obtener de forma dinámica la URL del microservicio y el token de autenticación.
 * 
 * Política ESTRICTA sin fallback: Si el registro o alguno de los parámetros requeridos
 * no existe o está vacío, lanza una Exception para detener la ejecución y evitar
 * conexiones a localhost o claves por defecto.
 * 
 * @return array ['url' => string, 'token' => string, 'env' => string]
 * @throws Exception Si no se encuentra configuración válida
 */
function of_http_get_hka_config() {
    sc_lookup(ds_env, "SELECT env, services FROM tb_env LIMIT 1");

    if (empty({ds_env}) || {ds_env} === false) {
        throw new Exception("No se encontró ningún registro de configuración en la tabla 'tb_env'.");
    }

    $env_name = isset({ds_env}[0][0]) ? trim({ds_env}[0][0]) : '';
    $services_raw = isset({ds_env}[0][1]) ? trim({ds_env}[0][1]) : '';

    if (empty($services_raw)) {
        throw new Exception("El campo 'services' en la tabla 'tb_env' está vacío.");
    }

    $services = json_decode($services_raw, true);
    if (!is_array($services)) {
        throw new Exception("El contenido de 'services' en 'tb_env' no es un JSON válido.");
    }

    $url = isset($services['hka_venezuela']) ? trim($services['hka_venezuela']) : '';
    $token = isset($services['hka_ve_token']) ? trim($services['hka_ve_token']) : '';

    // Validaciones estrictas sin fallback
    if (empty($url)) {
        throw new Exception("La URL del servicio ('hka_venezuela') no está configurada o está vacía en 'tb_env'.");
    }

    if (empty($token)) {
        throw new Exception("El token de autenticación ('hka_ve_token') no está configurado o está vacío en 'tb_env'.");
    }

    return [
        'url'   => rtrim($url, '/'),
        'token' => $token,
        'env'   => !empty($env_name) ? strtolower($env_name) : 'dev'
    ];
}

/**
 * Realiza una petición POST enviando datos JSON por cURL.
 * 
 * @param string $url URL destino
 * @param array|string $data Datos a enviar (si es array se convertirá a JSON)
 * @param string $token Token API Key opcional (para cabecera x-api-key)
 * @param string $env Nombre del entorno opcional (para cabecera x-environment)
 * @param int $timeout Tiempo de espera en segundos
 * @param array $headers Cabeceras HTTP adicionales
 * @return array Array asociativo con 'success', 'status', 'error' y 'body'
 */
function of_http_post_json($url, $data, $token = '', $env = '', $timeout = 30, $headers = []) {
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

    if (!empty($token)) {
        $default_headers[] = 'x-api-key: ' . $token;
    }
    if (!empty($env)) {
        $default_headers[] = 'x-environment: ' . $env;
    }
    
    $http_headers = array_merge($default_headers, $headers);
    
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $http_headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    // Omitir verificación SSL para certificados autofirmados o desarrollo
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
 * Realiza una petición GET mediante cURL.
 * 
 * @param string $url URL destino
 * @param string $token Token API Key opcional (para cabecera x-api-key)
 * @param string $env Nombre del entorno opcional (para cabecera x-environment)
 * @param int $timeout Tiempo de espera en segundos
 * @param array $headers Cabeceras HTTP adicionales
 * @return array Array asociativo con 'success', 'status', 'error' y 'body'
 */
function of_http_get($url, $token = '', $env = '', $timeout = 30, $headers = []) {
    $ch = curl_init($url);
    if ($ch === false) {
        return [
            'success' => false,
            'status' => 500,
            'error' => 'No se pudo inicializar cURL',
            'body' => null
        ];
    }
    
    $default_headers = [];
    if (!empty($token)) {
        $default_headers[] = 'x-api-key: ' . $token;
    }
    if (!empty($env)) {
        $default_headers[] = 'x-environment: ' . $env;
    }
    $http_headers = array_merge($default_headers, $headers);
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $http_headers);
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
