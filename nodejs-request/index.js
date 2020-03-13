const util = require('util');
const spawn = require('child_process').spawn;
const request = require('superagent');
const lineByLine = require('n-readlines');
const fs = require('fs');
const readline = require('readline');


const config = {
    // URL of the site
    //https://www.zillow.com/homedetails/577-Mulberry-Dr-Fleming-Island-FL-32003/43701102_zpid/
    uri: 'https://ifconfig.me/ip',
    listings_file: '../mongo/zillow/listings.json',
    listings_dir: '../listings/',
    // URL of Scrapoxy
    proxy: 'http://localhost:8888',
    proxyAdmin: 'http://localhost:8889',
    proxyAuth: 'Y3Jhd2xfbGlua3M=',
    // HTTPS over HTTP
    tunnel: false,
    
};

const MIN_PROXIES_REQUIRED=1;
let proxies = [];
const MAX_ACTIVE_REQUESTS=1;
let activeRequests = 0;
const MAX_LISTINGS_READ=1;
let listingsRead = 0;
const MAX_ZIPS_READ=4;
let zipsRead = 0;
const USER_AGENT_IOS = '-A "Mozilla/5.0 (iPhone; U; CPU iPhone OS 4_3_3 like Mac OS X; en-us) AppleWebKit/533.17.9 (KHTML, like Gecko) Version/5.0.2 Mobile/8J2 Safari/6533.18.5"';
const MAX_FAILED_IPS=1;
let failed_ips=0;

const start = Date.now();



console.log("Checking there are available proxies..");
waitForProxies( async (res) => {
    proxies = res;
    let curProxy = 0;
    const liner = new lineByLine(config.listings_file);
    let line= liner.next();
    while( zipsRead++ < MAX_ZIPS_READ && listingsRead < MAX_LISTINGS_READ && failed_ips < MAX_FAILED_IPS && line  ) {
        zip = JSON.parse(line);
        dir = config.listings_dir+zip.Zip;
        if(!fs.existsSync(dir) ) fs.mkdirSync(dir);
        console.log("Beginning zip",zip.Zip,zip.Homes.length);
        while(zip.Homes.length > 0 && listingsRead < MAX_LISTINGS_READ && failed_ips < MAX_FAILED_IPS) {
            const url = zip.Homes.pop();
            const a = url.split('/');
            let fname = a[4];
            if(url.indexOf("community") > -1){
                fname += a[5];
            }
            fname = dir + '/'+fname;
            if(fs.existsSync(fname)){
                console.log("Exists "+fname);
                continue;
            }
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
            if(curProxy >= proxies.length) curProxy = 0;
            console.log(proxies[curProxy].address);
            let curl = spawn('curl', 
                ['-L','-s','-x','http://'+proxies[curProxy].address.hostname+':'+proxies[curProxy].address.port,'-A',USER_AGENT_IOS,'-D',fname+'.headers','-o',fname+'.html',url],
                { });
            curl.stdout.on('data', (data)=>{ console.log("Curl data: ",data); });
            curl.stderr.on('data', (data)=>{ console.log("Curl error: ",data); });
            const usedProxy = proxies[curProxy];
            curl.on('close', (val) => {
                    //check headers file for http status
                    let lineReader = readline.createInterface({
                        input: fs.createReadStream( fname+'.headers'),
                    });
                    let lineCounter=0;
                    lineReader.on('line', function(line){
                        lineCounter++;
                        if(lineCounter==3){
                            console.log("status "+usedProxy.name+": ",line);
                            fs.writeFile(fname+'.proxy', JSON.stringify(usedProxy), (err) => {
                                if(err) console.log("Could not write proxy info to "+fname+'.proxy - ',err);
                            })
                            lineReader.close();
                            lineReader.removeAllListeners();
                            if( !/HTTP\/[\d\.]+\s200/.test(line) ){
                                //move html file so next run it tries it again
                                console.log("Failed "+fname+" - IP: "+usedProxy.address.hostname);
                                failed_ips++;
                                listingsRead--;
                                stopProxy(usedProxy.name);
                                fs.rename( fname+'.html', fname+'.FAILED.html', (err)=>{});
                            }
                        }
                    }) ;
                    activeRequests--;
            });
            curProxy++;
        }
        line = liner.next();
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


async function stopProxy( proxyName) {
    return request.post(config.proxyAdmin+'/api/instances/stop')
        .set('Authorization', config.proxyAuth)
        .set('Accept', 'application/json')
        .send({ name: proxyName });
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}