<?php
namespace SpotifyLyricsApi;

require_once __DIR__ . '/../vendor/autoload.php';

use Exception;

/**
* Class Spotify
*
* This class is responsible for interacting with the Spotify API.
*/

class Spotify
 {
    private $token_url = 'https://open.spotify.com/api/token';
    private $search_url = 'https://api.spotify.com/v1/search';
    private $lyrics_url = 'https://spclient.wg.spotify.com/color-lyrics/v2/track/';
    private $server_time_url = 'https://open.spotify.com/api/server-time';
    private $sp_dc;
    private $cache_file;

    /**
    * Spotify constructor.
    *
    * @param string $sp_dc The Spotify Data Controller ( sp_dc ) cookie value.
    */
    function __construct( $sp_dc )
    {
        $this->cache_file = sys_get_temp_dir() . '/spotify_token.json';
        $this->sp_dc = $sp_dc;
    }

    /**
    * Generates a Time-based One-Time Password (TOTP) using the server time.
    *
    * @param int $server_time_seconds The server time in seconds.
    * @return string The generated TOTP code.
    */
    function generate_totp( $server_time_seconds ) {
        $secret = "449443649084886328893534571041315";
        $version = 8;
        $period = 30;
        $digits = 6;
        
        $counter = floor($server_time_seconds / $period);
        $counter_bytes = pack('J', $counter); // 'J' packs as big-endian 64-bit unsigned integer
        
        $hmac_result = hash_hmac('sha1', $counter_bytes, $secret, true);
        
        $offset = ord($hmac_result[-1]) & 0x0F;
        $binary = (
            (ord($hmac_result[$offset]) & 0x7F) << 24
            | (ord($hmac_result[$offset + 1]) & 0xFF) << 16
            | (ord($hmac_result[$offset + 2]) & 0xFF) << 8
            | (ord($hmac_result[$offset + 3]) & 0xFF)
        );
        
        $code = $binary % pow(10, $digits);
        return str_pad($code, $digits, '0', STR_PAD_LEFT);
    }

    /**
    * Retrieves the server time and returns the parameters needed for the token request.
    *
    * @return array The parameters for the token request.
    * @throws SpotifyException If there is an error fetching the server time.
    */
    function getServerTimeParams(string $reason, string $product_type): array {
        try {
            $ch = curl_init();
            curl_setopt( $ch, CURLOPT_URL, $this->server_time_url );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
            $server_time_result = curl_exec( $ch );
            if ( $server_time_result === false ) {
                throw new SpotifyException( 'Failed to fetch server time: ' . curl_error( $ch ) );
            }
            $server_time_data = json_decode( $server_time_result, true );
            if ( !$server_time_data || !isset( $server_time_data[ 'serverTime' ] ) ) {
                throw new SpotifyException( 'Invalid server time response' );
            }
            $server_time_seconds = $server_time_data[ 'serverTime' ];

            $totp = $this->generate_totp( $server_time_seconds );

            $timestamp = time();
            $params = [
                'reason' => $reason,
                'productType' => $product_type,
                'totp' => $totp,
                'totpVer' => '5',
                'ts' => strval( $timestamp ),
            ];

            return $params;
        } catch ( Exception $e ) {
            throw new SpotifyException( $e->getMessage() );
        }
        finally {
            curl_close( $ch );
        }
    }

    /**
    * Retrieves an access token from Spotify and stores it in a file.
    * The file is stored in the temporary directory.
    *
    * @throws SpotifyException If there is an error during the token request.
    */
    function getToken(string $token_type, string $reason, string $product_type): void {
        if ( !$this->sp_dc ) {
            throw new SpotifyException( 'Please set SP_DC as an environmental variable.' );
        }
        try {
            $params = $this->getServerTimeParams($reason, $product_type);
            $headers = [
                'User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:124.0) Gecko/20100101 Firefox/124.0',
                'Cookie: sp_dc=' . $this->sp_dc
            ];
            $ch = curl_init();
            curl_setopt( $ch, CURLOPT_URL, $this->token_url . '?' . http_build_query( $params ) );
            curl_setopt( $ch, CURLOPT_TIMEOUT, 600 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
            curl_setopt( $ch, CURLOPT_VERBOSE, 0 );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
            curl_setopt( $ch, CURLOPT_HEADER, 0 );
            curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
            curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'GET' );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );

            $result = curl_exec( $ch );
            if ( $result === false ) {
                throw new SpotifyException( 'Token request failed: ' . curl_error( $ch ) );
            }
            $token_json = json_decode( $result, true );
            if ( !$token_json || ( isset( $token_json[ 'isAnonymous' ] ) && $token_json[ 'isAnonymous' ] ) ) {
                throw new SpotifyException( 'The SP_DC set seems to be invalid, please correct it!' );
            }

            $tokens = [];
            if (file_exists($this->cache_file)) {
                $tokens = json_decode(file_get_contents($this->cache_file), true) ?? [];
            }
            $tokens[$token_type] = $token_json;
            file_put_contents($this->cache_file, json_encode($tokens));
        } catch ( Exception $e ) {
            throw new SpotifyException( $e->getMessage() );
        }
        finally {
            curl_close( $ch );
        }
    }

    /**
    * Checks if the access token is expired and retrieves a new one if it is.
    * The function invokes getToken if the token is expired or the cache file is not found.
    */
    function checkTokenExpire($token_type): void {
        $reason = 'transport';
        $product_type = 'web-player';
        if ($token_type === 'search') {
            $reason = 'init';
            $product_type = 'mobile-web-player';
        }
        if (!file_exists($this->cache_file)) {
            $this->getToken($token_type, $reason, $product_type);
            return;
        }
        $tokens = json_decode(file_get_contents($this->cache_file), true);
        if (!isset($tokens[$token_type])) {
            $this->getToken($token_type, $reason, $product_type);
            return;
        }
        $tokenData = $tokens[$token_type];
        $timeleft = $tokenData['accessTokenExpirationTimestampMs'] ?? 0;
        $timenow = round(microtime(true) * 1000);

        if ($timeleft < $timenow) {
            $this->getToken($token_type, $reason, $product_type);
        }
    }

    public function searchTrack(string $query, int $limit): string
    {
        $params = http_build_query([
            'q' => $query,
            'type' => 'track',
            'limit' => $limit
        ]);

        $json = file_get_contents( $this->cache_file );
        $tokens = json_decode($json, true);
        if (!isset($tokens['search']['accessToken'])) {
            throw new \Exception('search token not found');
        }
        $token = $tokens['search']['accessToken'];

        $url = $this->search_url . '?' . $params;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $token"
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) {
            curl_close($ch);
            throw new \Exception('cURL error: ' . curl_error($ch));
        }
        curl_close($ch);

        if ($http_code !== 200) {
            return json_encode([
                'error' => true,
                'message' => 'Spotify API error',
                'status' => $http_code,
                'raw_response' => $response
            ]);
        }
        return $response;
    }


    /**
    * Retrieves the lyrics of a track from the Spotify.
    * @param string $track_id The Spotify track id.
    * @return string The lyrics of the track in JSON format.
    */
    function getLyrics( $track_id ): string 
    {
        $json = file_get_contents( $this->cache_file );
        $tokens = json_decode($json, true);
        if (!isset($tokens['lyrics']['accessToken'])) {
            throw new \Exception('lyrics token not found');
        }
        $token = $tokens['lyrics']['accessToken'];

        $formated_url = $this->lyrics_url . $track_id;
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'GET' );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
            'User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:124.0) Gecko/20100101 Firefox/124.0',
            'App-platform: WebPlayer',
            'Spotify-App-Version: 1.2.65.255.g85e641b4',
            'Referer: https://open.spotify.com/',
            'Origin: https://open.spotify.com/',
            'Accept: application/json',
            "authorization: Bearer $token"
        ) );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
        curl_setopt( $ch, CURLOPT_URL, $formated_url );
        $result = curl_exec( $ch );
        return $result;
    }

    /**
    * Retrieves the lyrics in LRC format.
    *
    * @param array $lyrics The lyrics data.
    * @return array The lyrics in LRC format.
    */

    function getLrcLyrics( $lyrics ): array 
    {
        $lrc = array();
        foreach ( $lyrics as $lines )
        {
            $lrctime = $this->formatMS( $lines[ 'startTimeMs' ] );
            array_push( $lrc, [ 'timeTag' => $lrctime, 'words' => $lines[ 'words' ] ] );
        }
        return $lrc;
    }

    /**
    * Retrieves the lyrics in SRT format.
    *
    * @param array $lyrics The lyrics data.
    * @return array The lyrics in SRT format.
    */

    function getSrtLyrics( $lyrics ): array 
    {
        $srt = array();
        for ( $i = 1; $i < count( $lyrics ); $i++ ) 
        {
            $srttime = $this->formatSRT( $lyrics[ $i-1 ][ 'startTimeMs' ] );
            $srtendtime = $this->formatSRT( $lyrics[ $i ][ 'startTimeMs' ] );
            array_push( $srt, [ 'index' => $i, 'startTime' => $srttime, 'endTime' => $srtendtime, 'words' => $lyrics[ $i-1 ][ 'words' ] ] );
        }
        return $srt;
    }

    /**
    * Helper fucntion for getLrcLyrics to change miliseconds to [ mm:ss.xx ]
    * @param int $milliseconds The time in miliseconds.
    * @return string The time in [ mm:ss.xx ] format.
    */

    function formatMS( $milliseconds ): string
    {
        $th_secs = intdiv( $milliseconds, 1000 );
        $lrc_timetag = sprintf( '%02d:%02d.%02d', intdiv( $th_secs, 60 ), $th_secs % 60, intdiv( ( $milliseconds % 1000 ), 10 ) );
        return $lrc_timetag;
    }

    /**
    * Helper function to format milliseconds to SRT time format ( hh:mm:ss, ms ).
    * @param int $milliseconds The time in milliseconds.
    * @return string The time in SRT format.
    */
    function formatSRT( $milliseconds ): string
    {
        $hours = intdiv( $milliseconds, 3600000 );
        $minutes = intdiv( $milliseconds % 3600000, 60000 );
        $seconds = intdiv( $milliseconds % 60000, 1000 );
        $milliseconds = $milliseconds % 1000;
        return sprintf( '%02d:%02d:%02d,%03d', $hours, $minutes, $seconds, $milliseconds );
    }
}
