/*
 * https://github.com/netnr/workers
 *
 * 2019-2022
 * netnr
 *
 * https://github.com/Rongronggg9/rsstt-img-relay
 *
 * 2021-2024
 * Rongronggg9
 */

/**
 * Configurations
 */
const DEFAULT_CONFIG = {
    selfURL: "", // to be filled later
    URLRegExp: "^(\\w+://.+?)/(.*)$",
    // 从 https://sematext.com/ 申请并修改令牌
    sematextToken: "",
    // 代理访问密钥。留空不校验；设置后请求必须带 ?key=同值
    proxyKey: "sumn_8880201",
    // 是否丢弃请求中的 Referer，在目标网站应用防盗链时有用
    dropReferer: true,
    // 个别站点强制不发送 Referer
    noRefererCDN: [],
    // chongbuluo workarounds
    chongbuluoCDN: ["static-beesseek.oss-cn-hangzhou.aliyuncs.com"],
    chongbuluoReferer: "https://www.chongbuluo.com/",
    // weibo workarounds
    weiboCDN: [".weibocdn.com", ".sinaimg.cn"],
    weiboReferer: "https://weibo.com/",
    // sspai workarounds
    sspaiCDN: [".sspai.com"],
    sspaiReferer: "https://sspai.com/",
    // douban workarounds
    doubanCDN: [".doubanio.com"],
    doubanReferer: "https://movie.douban.com/",
    // 黑名单，URL 中含有任何一个关键字都会被阻断
    blockList: [".m3u8", ".ts", ".acc", ".m4s", "photocall.tv", "googlevideo.com", "liveradio.ie"],
    // blockList: [],
    typeList: ["image", "video", "audio", "application", "font", "model"],
};

const CONFIG_KEYS = Object.keys(DEFAULT_CONFIG);
const DEFAULT_ALLOW_HEADERS = "Accept, Authorization, Cache-Control, Content-Type, DNT, If-Modified-Since, Keep-Alive, Origin, User-Agent, X-Requested-With, Token, x-access-token";
const ALLOW_METHODS = "GET, POST, PUT, PATCH, DELETE, OPTIONS";
const BODY_METHODS = new Set(["POST", "PUT", "PATCH", "DELETE"]);

let runtimeConfigCache = null;

/**
 * Set config from environmental variables once per isolate/env combination.
 * @param {object} env
 */
function getConfig(env = {}) {
    const envValues = {};
    let changed = runtimeConfigCache === null;

    for (const key of CONFIG_KEYS) {
        const value = env[key];
        envValues[key] = value;

        if (!changed && runtimeConfigCache.envValues[key] !== value) {
            changed = true;
        }
    }

    if (!changed) {
        return runtimeConfigCache.value;
    }

    const config = { ...DEFAULT_CONFIG };
    for (const key of CONFIG_KEYS) {
        const envValue = envValues[key];
        if (envValue === undefined || envValue === null || envValue === "") {
            continue;
        }

        config[key] = typeof DEFAULT_CONFIG[key] === "string" ? envValue : JSON.parse(envValue);
    }

    const normalizedConfig = {
        ...config,
        urlRegExp: new RegExp(config.URLRegExp),
        blockListLower: config.blockList.map((item) => item.toLowerCase()),
        typeListLower: config.typeList.map((item) => item.toLowerCase()),
        hasSematext: config.sematextToken.trim() !== "",
    };

    runtimeConfigCache = {
        envValues,
        value: normalizedConfig,
    };

    return normalizedConfig;
}

function createResponseHeaders(reqHeaders, sourceHeaders) {
    const headers = sourceHeaders ? new Headers(sourceHeaders) : new Headers();

    headers.set("Access-Control-Allow-Origin", "*");
    headers.set("Access-Control-Allow-Methods", ALLOW_METHODS);
    headers.set("Access-Control-Allow-Headers", reqHeaders.get("Access-Control-Allow-Headers") || DEFAULT_ALLOW_HEADERS);

    return headers;
}

function buildResponse(request, reqHeaders, ctx, runtimeConfig, body, init = {}, sourceHeaders) {
    const status = init.status ?? 200;
    const headers = createResponseHeaders(reqHeaders, sourceHeaders);

    if (init.contentType) {
        headers.set("content-type", init.contentType);
    }

    if (status < 400) {
        headers.set("cache-control", "public, max-age=604800");
    }

    const response = new Response(body, {
        status,
        statusText: init.statusText ?? "OK",
        headers,
    });

    if (runtimeConfig.hasSematext) {
        sematext.add(ctx, request, response, runtimeConfig);
    }

    return response;
}

function buildUpstreamHeaders(reqHeaders, runtimeConfig, url, hasRequestBody) {
    const headers = new Headers(reqHeaders);

    headers.delete("content-length");
    headers.delete("host");
    if (!hasRequestBody) {
        headers.delete("content-type");
    }

    if (runtimeConfig.dropReferer) {
        headers.delete("referer");

        const referer = getProxyReferer(url, runtimeConfig);
        if (referer !== "") {
            headers.set("referer", referer);
        }
    }

    return headers;
}

function getProxyReferer(url, runtimeConfig) {
    const upstreamUrl = new URL(url);
    const host = upstreamUrl.host;

    if (runtimeConfig.noRefererCDN.some((suffix) => hostMatches(host, suffix))) {
        return "";
    }
    if (runtimeConfig.chongbuluoCDN.some((suffix) => hostMatches(host, suffix))) {
        return runtimeConfig.chongbuluoReferer;
    }
    if (runtimeConfig.weiboCDN.some((suffix) => hostMatches(host, suffix))) {
        return runtimeConfig.weiboReferer;
    }
    if (runtimeConfig.sspaiCDN.some((suffix) => hostMatches(host, suffix))) {
        return runtimeConfig.sspaiReferer;
    }
    if (runtimeConfig.doubanCDN.some((suffix) => hostMatches(host, suffix))) {
        return runtimeConfig.doubanReferer;
    }

    return upstreamUrl.origin;
}

function hostMatches(host, suffix) {
    return host === suffix || host.endsWith(suffix);
}

/**
 * Event handler for fetchEvent
 * @param {Request} request
 * @param {object} env
 * @param {object} ctx
 */
async function fetchHandler(request, env, ctx) {
    ctx.passThroughOnException();
    const runtimeConfig = getConfig(env);
    const reqHeaders = request.headers;

    try {
        const requestUrl = new URL(request.url);
        if (runtimeConfig.proxyKey !== "" && requestUrl.searchParams.get("key") !== runtimeConfig.proxyKey) {
            return buildResponse(request, reqHeaders, ctx, runtimeConfig, JSON.stringify({
                code: 401,
                msg: "Invalid proxy key.",
            }), {
                status: 401,
                contentType: "application/json",
            });
        }

        let url = requestUrl.searchParams.get("url");
        if (url === null) {
            const urlMatch = runtimeConfig.urlRegExp.exec(request.url);
            if (!urlMatch) {
                throw new Error("Invalid request URL");
            }
            url = urlMatch[2];
        }

        if (url.includes("%")) {
            url = decodeURIComponent(url);
        }

        //需要忽略的代理
        if (request.method === "OPTIONS" || url.length < 3 || !url.includes(".") || url === "favicon.ico" || url === "robots.txt") {
            //输出提示
            const invalid = !(request.method === "OPTIONS" || url.length === 0);
            return buildResponse(request, reqHeaders, ctx, runtimeConfig, JSON.stringify({
                code: invalid ? 400 : 0,
                usage: "Host/{URL}",
                source: "https://github.com/Rongronggg9/rsstt-img-relay",
            }), {
                status: invalid ? 400 : 200,
                contentType: "application/json",
            });
        }
        //阻断
        if (blockUrl(url, runtimeConfig)) {
            return buildResponse(request, reqHeaders, ctx, runtimeConfig, JSON.stringify({
                code: 403,
                msg: "The keyword: " + runtimeConfig.blockList.join(" , ") + " was block-listed by the operator of this proxy.",
            }), {
                status: 403,
                contentType: "application/json",
            });
        }

        url = fixUrl(url);

        const hasRequestBody = BODY_METHODS.has(request.method);
        const fp = {
            method: request.method,
            headers: buildUpstreamHeaders(reqHeaders, runtimeConfig, url, hasRequestBody),
        };

        if (hasRequestBody) {
            fp.body = request.body;
        }

        // 发起 fetch
        const fr = await fetch(url, fp);
        const outCt = fr.headers.get("content-type");
        // 阻断
        if (blockType(outCt, runtimeConfig)) {
            if (fr.body) {
                fr.body.cancel();
            }

            return buildResponse(request, reqHeaders, ctx, runtimeConfig, JSON.stringify({
                    code: 415,
                    msg: "The keyword \"" + runtimeConfig.typeList.join(" , ") + "\" was whitelisted by the operator of this proxy, but got \"" + outCt + "\".",
                }), {
                status: 415,
                contentType: "application/json",
            });
        }

        const response = buildResponse(request, reqHeaders, ctx, runtimeConfig, fr.body, {
            status: fr.status,
            statusText: fr.statusText,
            contentType: outCt,
        }, fr.headers);
        const proxyReferer = fp.headers.get("referer");
        if (proxyReferer) {
            response.headers.set("X-Proxy-Referer", proxyReferer);
        }

        return response;
    } catch (err) {
        return buildResponse(request, reqHeaders, ctx, runtimeConfig, JSON.stringify({
            code: -1,
            msg: JSON.stringify(err.stack) || err,
        }), {
            status: 500,
            contentType: "application/json",
        });
    }

    // return new Response('OK', { status: 200 })
}

// 补齐 url
function fixUrl(url) {
    if (url.includes("://")) {
        return url;
    } else if (url.includes(':/')) {
        return url.replace(':/', '://');
    } else {
        return "http://" + url;
    }
}

// 阻断 url
function blockUrl(url, runtimeConfig) {
    const lowerUrl = url.toLowerCase();
    return runtimeConfig.blockListLower.some((item) => lowerUrl.includes(item));
}
// 阻断 type
function blockType(type, runtimeConfig) {
    if (!type || typeof type !== 'string') {
        return false;
    }

    const lowerType = type.toLowerCase();
    return !runtimeConfig.typeListLower.some((item) => lowerType.includes(item));
}

/**
 * 日志
 */
const sematext = {

    /**
     * 构建发送主体
     * @param {any} request
     * @param {any} response
     */
    buildBody: (request, response) => {
        const hua = request.headers.get("user-agent")
        const hip = request.headers.get("cf-connecting-ip")
        const hrf = request.headers.get("referer")
        const url = new URL(request.url)

        const body = {
            method: request.method,
            statusCode: response.status,
            clientIp: hip,
            referer: hrf,
            userAgent: hua,
            host: url.host,
            path: url.pathname,
            proxyHost: null,
        }

        if (body.path.includes(".") && body.path != "/" && !body.path.includes("favicon.ico")) {
            try {
                let purl = body.path.substring(1);
                if (purl.includes("%")) {
                    purl = decodeURIComponent(purl);
                }
                purl = fixUrl(purl);

                body.path = purl;
                body.proxyHost = new URL(purl).host;
            } catch { }
        }

        return {
            method: "POST",
            body: JSON.stringify(body)
        }
    },

    /**
     * 添加
     * @param {any} event
     * @param {any} request
     * @param {any} response
     * @param {any} runtimeConfig
     */
    add: (event, request, response, runtimeConfig) => {
        let url = `https://logsene-receiver.sematext.com/${runtimeConfig.sematextToken}/example/`;
        const body = sematext.buildBody(request, response);

        event.waitUntil(fetch(url, body).catch(() => {}))
    }
};

export default {
    fetch: fetchHandler
};
