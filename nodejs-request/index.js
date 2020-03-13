const util = require('util');
const spawn = require('child_process').spawn;
const request = require('superagent');

const config = {
    // URL of the site
    //https://www.zillow.com/homedetails/577-Mulberry-Dr-Fleming-Island-FL-32003/43701102_zpid/
    uri: 'https://ifconfig.me/ip',
    //opts: {
        // URL of Scrapoxy
        proxy: 'http://localhost:8888',
        proxyAdmin: 'http://localhost:8889',
        proxyAuth: 'Y3Jhd2xfbGlua3M=',
        // HTTPS over HTTP
        tunnel: false,
    //}
};

const MIN_PROXIES_REQUIRED=1;
let proxies = [];
const MAX_ACTIVE_REQUESTS=3;
let activeRequests = 0;
const MAX_LISTINGS_READ=3;
let listingsRead = 0;
const MAX_ZIPS_READ=1;
let zipsRead = 0;
const USER_AGENT_IOS = '-A "Mozilla/5.0 (iPhone; U; CPU iPhone OS 4_3_3 like Mac OS X; en-us) AppleWebKit/533.17.9 (KHTML, like Gecko) Version/5.0.2 Mobile/8J2 Safari/6533.18.5"';

const start = Date.now();

let urls =[];
for(let i=0; i<5;i++){
    urls.push( 'https://ifconfig.me/ip' );
}

console.log("Checking there are available proxies..");
waitForProxies( async (res) => {
    proxies = res;
    while(urls.length > 0 && listingsRead <= MAX_LISTINGS_READ ) {
        const url = urls.pop();
        console.log("Next url", url, '#'+listingsRead, proxies.length+' > '+MIN_PROXIES_REQUIRED+' proxies. ', activeRequests+' / '+MAX_ACTIVE_REQUESTS+' requests. ');
        while( proxies.length < MIN_PROXIES_REQUIRED ) {
            const res = await getProxyStatus();
            proxies = res.body;
            await sleep(5000);
        }
        while( activeRequests >= MAX_ACTIVE_REQUESTS) {
            await(sleep(500));
        }
        activeRequests++;
        listingsRead++;
        let curl = spawn('curl', 
            ['-L','-s','-x',config.proxy,'-A',USER_AGENT_IOS,'-D','./testcurl'+listingsRead+'.headers','-o','./testcurl'+listingsRead+'.html',url],
            { });
        curl.stdout.on('data', (data)=>{ console.log("Curl data: ",data); });
        curl.stderr.on('data', (data)=>{ console.log("Curl error: ",data); });
        curl.on('close', (val) => {
                console.log("Curl finished",val);
                activeRequests--;
        });
    }
    console.log("Waiting for any active requests to finish up");
    while(activeRequests > 0 ){
        await sleep(1000);
    }
    process.exit(0);
    
});


function waitForProxies(cb){
    getProxyStatus().then( (res) => {
        if( Array.isArray(res.body) && res.body.length >= MIN_PROXIES_REQUIRED ) {
            cb( res.body );
        } else {
            sleep(5000).then(
                ()=> { waitForProxies(cb); }
            );
        }
    }).catch( (e) => {
        console.log("Caught error waiting", e.toString() );
    });
}

async function getProxyStatus() {
    return request.get(config.proxyAdmin+'/api/instances')
        .set('Authorization', config.proxyAuth)
        .set('Accept', 'application/json');
}

async function sendCurlRequest( ) {

}

async function stopProxy( proxyName) {

}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}