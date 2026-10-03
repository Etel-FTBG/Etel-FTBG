require('dotenv').config();

const base = require('./app.base.json').expo;

module.exports = {
  expo: {
    ...base,
    android: {
      ...base.android,
      config: {
        googleMaps: {
          apiKey: process.env.GOOGLE_MAPS_API_KEY || '',
        },
      },
    },
    ios: {
      ...base.ios,
      config: {
        googleMapsApiKey: process.env.GOOGLE_MAPS_API_KEY || '',
      },
    },
    extra: {
      ...base.extra,
      etelApiBaseUrl: process.env.ETEL_API_BASE_URL || base.extra.etelApiBaseUrl,
      eas: {
        projectId: 'facee9c3-0696-4bbc-a0dd-332240b0e200',
      },
    },
    owner: 'etel-ftbg',
  },
};
